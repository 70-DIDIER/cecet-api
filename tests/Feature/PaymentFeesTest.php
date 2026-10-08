<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Membership\Models\Membership;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentIntentStatus;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Payment\PaymentFeeCalculator;
use App\Domain\Payment\Providers\Fake\FakePaymentProvider;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->settings = app(SettingsService::class);
    $this->calculator = app(PaymentFeeCalculator::class);
});

function pendingMemberToken(): array
{
    $member = User::factory()->status(UserStatus::PendingPayment)->create();

    return [$member, tokenFor($member)];
}

describe('fee calculation', function () {
    it('adds the fees on top so the cooperative receives its net amount', function (int $rateBps, int $fixed, int $rounding, int $net, int $expectedTotal) {
        setFeeRules(['fake' => ['rate_bps' => $rateBps, 'fixed_xof' => $fixed, 'rounding_xof' => $rounding]]);

        $breakdown = $this->calculator->forNet('fake', $net);

        expect($breakdown->net)->toBe($net)
            ->and($breakdown->total)->toBe($expectedTotal)
            ->and($breakdown->fee)->toBe($expectedTotal - $net);
    })->with([
        'no fees' => [0, 0, 1, 25000, 25000],
        '3 % rounded up to the unit' => [300, 0, 1, 25000, 25774],
        '3 % plus a fixed part' => [300, 100, 1, 25000, 25877],
        '3 % rounded to 50 F' => [300, 0, 50, 25000, 25800],
        'fixed fee only' => [0, 150, 1, 200000, 200150],
        'legal person at 1.8 %' => [180, 0, 1, 200000, 203666],
    ]);

    it('never lets the provider fee on the total eat into the net amount', function () {
        foreach ([0, 1, 50, 150, 180, 300, 1000, 4999] as $rate) {
            foreach ([0, 25, 100, 500] as $fixed) {
                foreach ([1, 5, 50] as $rounding) {
                    setFeeRules(['fake' => ['rate_bps' => $rate, 'fixed_xof' => $fixed, 'rounding_xof' => $rounding]]);

                    foreach ([1, 999, 25000, 200000, 1500001, 35000000] as $net) {
                        $breakdown = $this->calculator->forNet('fake', $net);
                        $providerFee = intdiv($breakdown->total * $rate + 9999, 10000) + $fixed;

                        expect($breakdown->total - $providerFee)->toBeGreaterThanOrEqual($net, "rate={$rate} fixed={$fixed} rounding={$rounding} net={$net}")
                            ->and($breakdown->total % $rounding)->toBe(0)
                            ->and($breakdown->fee)->toBeGreaterThanOrEqual(0);
                    }
                }
            }
        }
    });

    it('charges no fees when the cooperative absorbs them', function () {
        $this->settings->set('payment.customer_bears_fees', false, 'La CECT prend les frais en charge');

        $breakdown = $this->calculator->forNet('fedapay', 25000);

        expect($breakdown->fee)->toBe(0)->and($breakdown->total)->toBe(25000);
    });

    it('refuses a provider whose fees were never configured instead of charging zero', function () {
        expect(fn () => $this->calculator->forNet('fedapay', 25000))
            ->toThrow(Exception::class, 'frais de transaction');
    });

    it('rejects absurd fee rules', function (array $rules) {
        expect(fn () => $this->settings->set('payment.customer_fees', $rules, 'Barème invalide'))
            ->toThrow(Exception::class);
    })->with([
        'rate above 50 %' => [['fedapay' => ['rate_bps' => 6000, 'fixed_xof' => 0]]],
        'negative fixed part' => [['fedapay' => ['rate_bps' => 100, 'fixed_xof' => -1]]],
        'missing fixed part' => [['fedapay' => ['rate_bps' => 100]]],
        'zero rounding' => [['fedapay' => ['rate_bps' => 100, 'fixed_xof' => 0, 'rounding_xof' => 0]]],
    ]);
});

describe('membership payment with customer-borne fees', function () {
    beforeEach(function () {
        setFeeRules(['fake' => ['rate_bps' => 300, 'fixed_xof' => 0, 'rounding_xof' => 1]]);
    });

    it('quotes the member the total to pay, with the fee shown separately', function () {
        [, $token] = pendingMemberToken();

        $this->withToken($token)->getJson('/api/v1/me/membership')
            ->assertOk()
            ->assertJsonPath('data.fee_due', 25000)
            ->assertJsonPath('data.payment_quote.net_amount', 25000)
            ->assertJsonPath('data.payment_quote.fee_amount', 774)
            ->assertJsonPath('data.payment_quote.total_amount', 25774)
            ->assertJsonPath('data.payment_quote.fees_borne_by', 'member');
    });

    it('charges the total at the provider and records net, fee and total', function () {
        [$member, $token] = pendingMemberToken();

        $response = $this->withToken($token)
            ->postJson('/api/v1/me/membership/payments', [], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertCreated()
            ->assertJsonPath('data.amount', 25774)
            ->assertJsonPath('data.fee_amount', 774)
            ->assertJsonPath('data.net_amount', 25000);

        $intent = PaymentIntent::query()->sole();
        expect($intent->amount)->toBe(25000)
            ->and($intent->fee_amount)->toBe(774)
            ->and($intent->total_amount)->toBe(25774)
            ->and($intent->metadata['fee_rule'])->toMatchArray(['rate_bps' => 300, 'provider' => 'fake'])
            ->and(Membership::query()->where('user_id', $member->id)->sole()->fee_amount)->toBe(25000);

        $fakeTransaction = app(FakePaymentProvider::class)
            ->fetchTransaction((string) PaymentAttempt::query()->findOrFail($response->json('data.id'))->provider_transaction_id);

        expect($fakeTransaction->amount)->toBe(25774)
            ->and($fakeTransaction->metadata)->toMatchArray(['net_amount' => '25000', 'fee_amount' => '774']);
    });

    it('activates the account when the customer paid the total, and audits the breakdown', function () {
        [$member, $token] = pendingMemberToken();
        $attemptId = $this->withToken($token)->postJson('/api/v1/me/membership/payments', [], ['Idempotency-Key' => (string) Str::ulid()])->json('data.id');

        settleFakeAttempt($attemptId, ProviderPaymentStatus::Approved)->assertOk();

        expect($member->refresh()->status)->toBe(UserStatus::Active)
            ->and(PaymentIntent::query()->sole()->status)->toBe(PaymentIntentStatus::Succeeded);

        $audit = AuditEvent::query()->where('action', 'memberships.activated')->sole();
        expect($audit->metadata)->toMatchArray(['amount' => 25000, 'transaction_fee' => 774, 'total_paid' => 25774]);
    });

    it('does not activate when the customer paid only the net amount', function () {
        [$member, $token] = pendingMemberToken();
        $attemptId = $this->withToken($token)->postJson('/api/v1/me/membership/payments', [], ['Idempotency-Key' => (string) Str::ulid()])->json('data.id');

        settleFakeAttempt($attemptId, ProviderPaymentStatus::Approved, amount: 25000)->assertOk();

        $attempt = PaymentAttempt::query()->findOrFail($attemptId);
        expect($member->refresh()->status)->toBe(UserStatus::PendingPayment)
            ->and($attempt->status)->toBe(PaymentAttemptStatus::Mismatch)
            ->and($attempt->requires_review)->toBeTrue();
    });

    it('opens a new intent when the fee rule changed before any payment', function () {
        [, $token] = pendingMemberToken();
        $first = $this->withToken($token)->postJson('/api/v1/me/membership/payments', [], ['Idempotency-Key' => (string) Str::ulid()])->json('data.id');
        settleFakeAttempt($first, ProviderPaymentStatus::Canceled)->assertOk();

        setFeeRules(['fake' => ['rate_bps' => 150, 'fixed_xof' => 0, 'rounding_xof' => 1]]);

        $this->withToken($token)->postJson('/api/v1/me/membership/payments', [], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertCreated()
            ->assertJsonPath('data.amount', 25381)
            ->assertJsonPath('data.fee_amount', 381);

        expect(PaymentIntent::query()->where('status', PaymentIntentStatus::Canceled)->sole()->total_amount)->toBe(25774)
            ->and(PaymentIntent::query()->where('status', PaymentIntentStatus::Open)->sole()->total_amount)->toBe(25381);
    });

    it('charges a legal person the larger fee on its own fee', function () {
        $member = User::factory()->status(UserStatus::PendingPayment)->legalPerson()->create();

        $this->withToken(tokenFor($member))->postJson('/api/v1/me/membership/payments', [], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertCreated()
            ->assertJsonPath('data.net_amount', 200000)
            ->assertJsonPath('data.amount', 206186)
            ->assertJsonPath('data.fee_amount', 6186);
    });
});

describe('provider without a fee rule', function () {
    it('blocks the payment before any provider call or attempt when FedaPay fees are not configured', function () {
        config(['services.fedapay.secret_key' => 'sk_test']);
        $this->settings->set('payment.inbound_provider', 'fedapay', 'Passage à FedaPay');
        Http::fake();
        [, $token] = pendingMemberToken();

        $this->withToken($token)->postJson('/api/v1/me/membership/payments', [], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertStatus(503)
            ->assertJsonPath('code', 'PAYMENT_FEES_NOT_CONFIGURED')
            ->assertJsonPath('details.provider', 'fedapay');

        Http::assertNothingSent();
        expect(PaymentAttempt::query()->count())->toBe(0)
            ->and(PaymentIntent::query()->count())->toBe(0);

        $this->withToken($token)->getJson('/api/v1/me/membership')->assertOk()->assertJsonPath('data.payment_quote', null);
    });

    it('sends the fee-inclusive amount to FedaPay once the rule is configured', function () {
        config(['services.fedapay.secret_key' => 'sk_test']);
        $this->settings->set('payment.inbound_provider', 'fedapay', 'Passage à FedaPay');
        setFeeRules(['fedapay' => ['rate_bps' => 180, 'fixed_xof' => 0, 'rounding_xof' => 1]]);
        Http::fake([
            '*/transactions' => Http::response(['v1/transaction' => ['id' => 31, 'reference' => 'trx_31']], 201),
            '*/transactions/31/token' => Http::response(['token' => 't', 'url' => 'https://checkout.fedapay.test/31']),
        ]);
        [, $token] = pendingMemberToken();

        $this->withToken($token)->postJson('/api/v1/me/membership/payments', [], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertCreated()
            ->assertJsonPath('data.amount', 25459)
            ->assertJsonPath('data.net_amount', 25000);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transactions')
            && $request['amount'] === 25459
            && $request['custom_metadata']['net_amount'] === '25000'
            && $request['custom_metadata']['fee_amount'] === '459');
    });
});
