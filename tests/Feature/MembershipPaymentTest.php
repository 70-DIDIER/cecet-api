<?php

use App\Domain\Access\AccountManager;
use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Membership\Models\Membership;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentIntentStatus;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Enums\WebhookEventStatus;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Payment\Models\ProviderWebhookEvent;
use App\Domain\Payment\PaymentProviderManager;
use App\Domain\Payment\Providers\Fake\FakePaymentProvider;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->fake = app(FakePaymentProvider::class);
    $this->member = User::factory()->status(UserStatus::PendingPayment)->create();
});

function startMembershipPayment(User $member): TestResponse
{
    return test()->withToken(tokenFor($member))
        ->postJson('/api/v1/me/membership/payments', [], ['Idempotency-Key' => (string) Str::ulid()]);
}

it('shows the fee due, computed by the server, before any payment', function (bool $legal, int $expectedFee) {
    $member = User::factory()->status(UserStatus::PendingPayment)->when($legal, fn ($factory) => $factory->legalPerson())->create();

    test()->withToken(tokenFor($member))->getJson('/api/v1/me/membership')
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_payment')
        ->assertJsonPath('data.fee_due', $expectedFee)
        ->assertJsonPath('data.fee_currency', 'XOF')
        ->assertJsonPath('data.membership', null);
})->with([
    'natural person' => [false, 25000],
    'legal person' => [true, 200000],
]);

it('opens a hosted checkout for the configured fee and ignores any amount sent by the client', function () {
    $response = $this->withToken(tokenFor($this->member))
        ->postJson('/api/v1/me/membership/payments', ['amount' => 1], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount', 25000)
        ->assertJsonPath('data.currency', 'XOF')
        ->assertJsonPath('data.provider', 'fake')
        ->assertJsonPath('data.simulation_available', true);

    expect($response->json('data.checkout_url'))->toStartWith('http');

    $membership = Membership::query()->where('user_id', $this->member->id)->sole();
    $intent = PaymentIntent::query()->sole();

    expect($membership->fee_amount)->toBe(25000)
        ->and($membership->fee_setting_key)->toBe('membership.fee.natural_person')
        ->and($intent->amount)->toBe(25000)
        ->and($intent->status)->toBe(PaymentIntentStatus::Open)
        ->and($this->member->refresh()->status)->toBe(UserStatus::PendingPayment);
});

it('uses the payment phone supplied by the member for a hosted FedaPay checkout', function () {
    config(['services.fedapay.secret_key' => 'sk_sandbox_test']);
    app(SettingsService::class)->set('payment.inbound_provider', 'fedapay', 'Passage à FedaPay');
    app(SettingsService::class)->set('payment.customer_bears_fees', false, 'La coopérative absorbe les frais');

    Http::fake([
        'sandbox-api.fedapay.com/v1/transactions' => Http::response(['v1/transaction' => ['id' => 77, 'reference' => 'trx_77']], 201),
        'sandbox-api.fedapay.com/v1/transactions/77/token' => Http::response(['token' => 'tok_77', 'url' => 'https://checkout-sandbox.fedapay.com/tok_77']),
    ]);

    $this->withToken(tokenFor($this->member))
        ->postJson('/api/v1/me/membership/payments', [
            'method' => 'TMONEY',
            'payment_phone' => '+22890112233',
        ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()
        ->assertJsonPath('data.provider', 'fedapay')
        ->assertJsonPath('data.status', 'pending');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://sandbox-api.fedapay.com/v1/transactions'
        && $request['customer']['phone_number'] === ['number' => '90112233', 'country' => 'tg']);
});

it('rejects an invalid payment phone before contacting the provider', function () {
    Http::fake();

    $this->withToken(tokenFor($this->member))
        ->postJson('/api/v1/me/membership/payments', [
            'method' => 'TMONEY',
            'payment_phone' => '90112233',
        ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(422);

    Http::assertNothingSent();
});

it('returns the checkout already awaiting payment instead of opening a second one', function () {
    $first = startMembershipPayment($this->member)->assertCreated()->json('data.id');
    $second = startMembershipPayment($this->member)->assertOk()->json('data.id');

    expect($second)->toBe($first)
        ->and(PaymentAttempt::query()->count())->toBe(1);
});

it('expires a stale hosted checkout when the membership page is loaded', function () {
    $attemptId = startMembershipPayment($this->member)->assertCreated()->json('data.id');

    $this->travel(61)->minutes();

    $this->withToken(tokenFor($this->member))->getJson('/api/v1/me/membership')
        ->assertOk()
        ->assertJsonPath('data.payment_attempts.0.id', $attemptId)
        ->assertJsonPath('data.payment_attempts.0.status', 'expired');

    expect(PaymentAttempt::query()->findOrFail($attemptId)->status)->toBe(PaymentAttemptStatus::Expired);
});

it('activates the account only when a signed webhook is confirmed by the provider', function () {
    $attemptId = startMembershipPayment($this->member)->json('data.id');

    $this->withToken(tokenFor($this->member))->getJson('/api/v1/me/membership')->assertJsonPath('data.account_status', 'pending_payment');

    settleFakeAttempt($attemptId, ProviderPaymentStatus::Approved)->assertOk()->assertJson(['received' => true, 'duplicate' => false]);

    expect($this->member->refresh()->status)->toBe(UserStatus::Active);

    $membership = Membership::query()->sole();
    expect($membership->status->value)->toBe('active')
        ->and($membership->activation_mode->value)->toBe('payment')
        ->and($membership->paid_at)->not->toBeNull()
        ->and(PaymentIntent::query()->sole()->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and(PaymentAttempt::query()->sole()->status)->toBe(PaymentAttemptStatus::Approved)
        ->and(ProviderWebhookEvent::query()->sole()->status)->toBe(WebhookEventStatus::Processed);

    $audit = AuditEvent::query()->where('action', 'memberships.activated')->sole();
    expect($audit->subject_type)->toBe('membership')
        ->and($audit->before['account_status'])->toBe('pending_payment')
        ->and($audit->after['account_status'])->toBe('active')
        ->and($audit->metadata['amount'])->toBe(25000);

    $this->withToken(tokenFor($this->member))->getJson('/api/v1/me/membership')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.fee_due', 0);
});

it('rejects a webhook with an invalid signature and changes nothing', function () {
    $attemptId = startMembershipPayment($this->member)->json('data.id');
    $attempt = PaymentAttempt::query()->findOrFail($attemptId);
    $webhook = $this->fake->simulate((string) $attempt->provider_transaction_id, ProviderPaymentStatus::Approved);

    sendFakeWebhook($webhook['body'], [FakePaymentProvider::SIGNATURE_HEADER => str_repeat('a', 64)])
        ->assertStatus(400)
        ->assertJsonPath('code', 'WEBHOOK_REJECTED');

    sendFakeWebhook($webhook['body'].' ', $webhook['headers'])->assertStatus(400);
    sendFakeWebhook($webhook['body'], [])->assertStatus(400);

    expect($this->member->refresh()->status)->toBe(UserStatus::PendingPayment)
        ->and(ProviderWebhookEvent::query()->count())->toBe(0)
        ->and(SecurityEvent::query()->where('event_type', 'webhook.invalid_signature')->count())->toBe(3);
});

it('acknowledges a duplicate webhook without processing it twice', function () {
    $attemptId = startMembershipPayment($this->member)->json('data.id');

    settleFakeAttempt($attemptId, ProviderPaymentStatus::Approved, eventId: 'evt_same')->assertJson(['duplicate' => false]);
    settleFakeAttempt($attemptId, ProviderPaymentStatus::Approved, eventId: 'evt_same')->assertOk()->assertJson(['duplicate' => true]);

    expect(ProviderWebhookEvent::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'memberships.activated')->count())->toBe(1)
        ->and(SecurityEvent::query()->where('event_type', 'webhook.duplicate')->count())->toBe(1);
});

it('does not trust the content of a signed webhook: the provider has the last word', function () {
    $attemptId = startMembershipPayment($this->member)->json('data.id');
    $attempt = PaymentAttempt::query()->findOrFail($attemptId);

    $webhook = $this->fake->signedWebhook('transaction.approved', (string) $attempt->provider_transaction_id);
    sendFakeWebhook($webhook['body'], $webhook['headers'])->assertOk();

    expect($this->member->refresh()->status)->toBe(UserStatus::PendingPayment)
        ->and($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Pending);
});

it('flags a payment whose amount differs from the intent and does not activate', function () {
    $attemptId = startMembershipPayment($this->member)->json('data.id');

    settleFakeAttempt($attemptId, ProviderPaymentStatus::Approved, amount: 100)->assertOk();

    $attempt = PaymentAttempt::query()->findOrFail($attemptId);
    expect($this->member->refresh()->status)->toBe(UserStatus::PendingPayment)
        ->and($attempt->status)->toBe(PaymentAttemptStatus::Mismatch)
        ->and($attempt->requires_review)->toBeTrue()
        ->and($attempt->review_reason)->toBe('amount_or_reference_mismatch')
        ->and(PaymentIntent::query()->sole()->status)->toBe(PaymentIntentStatus::Open);

    $event = SecurityEvent::query()->where('event_type', 'payment.amount_or_reference_mismatch')->sole();
    expect($event->severity->value)->toBe('critical')
        ->and($event->metadata['received_amount'])->toBe(100);
});

it('lets a member retry after a declined payment', function () {
    $first = startMembershipPayment($this->member)->json('data.id');
    settleFakeAttempt($first, ProviderPaymentStatus::Declined)->assertOk();

    expect(PaymentAttempt::query()->findOrFail($first)->status)->toBe(PaymentAttemptStatus::Declined);

    $second = startMembershipPayment($this->member)->assertCreated()->json('data.id');
    settleFakeAttempt($second, ProviderPaymentStatus::Approved)->assertOk();

    expect($second)->not->toBe($first)
        ->and($this->member->refresh()->status)->toBe(UserStatus::Active)
        ->and(PaymentIntent::query()->count())->toBe(1);
});

it('flags a second approved payment of the same intent for refund review', function () {
    $first = startMembershipPayment($this->member)->json('data.id');

    $this->travel(61)->minutes();
    $second = startMembershipPayment($this->member)->assertCreated()->json('data.id');
    expect(PaymentAttempt::query()->findOrFail($first)->status)->toBe(PaymentAttemptStatus::Expired);

    settleFakeAttempt($first, ProviderPaymentStatus::Approved)->assertOk();
    settleFakeAttempt($second, ProviderPaymentStatus::Approved)->assertOk();

    $duplicate = PaymentAttempt::query()->findOrFail($second);
    expect($this->member->refresh()->status)->toBe(UserStatus::Active)
        ->and(PaymentAttempt::query()->findOrFail($first)->status)->toBe(PaymentAttemptStatus::Approved)
        ->and($duplicate->status)->toBe(PaymentAttemptStatus::Approved)
        ->and($duplicate->requires_review)->toBeTrue()
        ->and($duplicate->review_reason)->toBe('duplicate_payment')
        ->and(SecurityEvent::query()->where('event_type', 'payment.duplicate_payment')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'memberships.activated')->count())->toBe(1);
});

it('replaces an unpaid intent when the configured fee changed', function () {
    $first = startMembershipPayment($this->member)->json('data.id');
    settleFakeAttempt($first, ProviderPaymentStatus::Canceled)->assertOk();

    app(SettingsService::class)->set('membership.fee.natural_person', 30000, 'Nouvelle grille tarifaire');

    $second = startMembershipPayment($this->member)->assertCreated()->assertJsonPath('data.amount', 30000)->json('data.id');

    $intents = PaymentIntent::query()->orderBy('id')->get();
    expect($intents)->toHaveCount(2)
        ->and($intents[0]->status)->toBe(PaymentIntentStatus::Canceled)
        ->and($intents[0]->amount)->toBe(25000)
        ->and($intents[1]->status)->toBe(PaymentIntentStatus::Open)
        ->and(Membership::query()->sole()->fee_amount)->toBe(30000)
        ->and(AuditEvent::query()->where('action', 'memberships.fee_changed')->exists())->toBeTrue();

    settleFakeAttempt($second, ProviderPaymentStatus::Approved, amount: 25000)->assertOk();
    expect(PaymentAttempt::query()->findOrFail($second)->status)->toBe(PaymentAttemptStatus::Mismatch);
});

it('abandons a checkout stuck before reaching the provider and allows a new one', function () {
    $intent = PaymentIntent::query()->create([
        'user_id' => $this->member->id,
        'purpose' => 'membership',
        'payable_type' => 'membership',
        'payable_id' => ($membership = Membership::query()->create([
            'user_id' => $this->member->id, 'status' => 'pending_payment', 'fee_amount' => 25000, 'fee_setting_key' => 'membership.fee.natural_person',
        ]))->id,
        'amount' => 25000,
        'fee_amount' => 0,
        'total_amount' => 25000,
        'currency' => 'XOF',
        'status' => PaymentIntentStatus::Open,
    ]);
    $stuck = $intent->attempts()->create(['provider' => 'fake', 'status' => PaymentAttemptStatus::Created, 'amount' => 25000, 'currency' => 'XOF']);

    startMembershipPayment($this->member)->assertStatus(409)->assertJsonPath('code', 'PAYMENT_ATTEMPT_IN_PROGRESS');

    $this->travel(121)->seconds();
    startMembershipPayment($this->member)->assertCreated();

    expect($stuck->refresh()->status)->toBe(PaymentAttemptStatus::Failed)
        ->and($stuck->failure_reason)->toBe('abandoned_before_checkout');
});

it('reconciles a lost webhook when the member asks for a refresh', function () {
    $attemptId = startMembershipPayment($this->member)->json('data.id');
    $attempt = PaymentAttempt::query()->findOrFail($attemptId);

    $this->fake->simulate((string) $attempt->provider_transaction_id, ProviderPaymentStatus::Approved);

    $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/membership/payments/{$attemptId}/refresh")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.checkout_url', null);

    expect($this->member->refresh()->status)->toBe(UserStatus::Active);
});

it('keeps attempts private to their owner', function () {
    $attemptId = startMembershipPayment($this->member)->json('data.id');
    $stranger = User::factory()->status(UserStatus::PendingPayment)->create();

    $this->withToken(tokenFor($stranger))->getJson("/api/v1/me/membership/payments/{$attemptId}")->assertNotFound();
    $this->withToken(tokenFor($stranger))->postJson("/api/v1/me/membership/payments/{$attemptId}/refresh")->assertNotFound();
    $this->withToken(tokenFor($this->member))->getJson("/api/v1/me/membership/payments/{$attemptId}")->assertOk();
});

it('refuses payments for active members, internal accounts and unauthenticated callers', function () {
    $active = User::factory()->create();
    $staff = User::factory()->staff(PermissionCatalog::ADMIN)->create();

    startMembershipPayment($active)->assertStatus(409)->assertJsonPath('code', 'MEMBERSHIP_ALREADY_ACTIVE');
    startMembershipPayment($staff)->assertForbidden()->assertJsonPath('code', 'MEMBERSHIP_NOT_PAYABLE');
    $this->flushHeaders();
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/me/membership/payments', [], ['Idempotency-Key' => (string) Str::ulid()])->assertUnauthorized();
    $this->postJson('/api/v1/me/membership/payments')->assertUnauthorized();
});

it('reports a missing provider key as unavailable without leaving a usable attempt behind', function () {
    config(['services.fedapay.secret_key' => '']);
    app(SettingsService::class)->set('payment.inbound_provider', 'fedapay', 'Passage à FedaPay');
    app(SettingsService::class)->set('payment.customer_bears_fees', false, 'La coopérative absorbe les frais');

    startMembershipPayment($this->member)
        ->assertStatus(503)
        ->assertJsonPath('code', 'PAYMENT_PROVIDER_UNAVAILABLE');

    $attempt = PaymentAttempt::query()->sole();
    expect($attempt->status)->toBe(PaymentAttemptStatus::Failed)
        ->and($attempt->failure_reason)->toBe('provider_error')
        ->and($attempt->checkout_url)->toBeNull();
});

it('reports a provider outage without leaving a usable attempt behind', function () {
    config(['services.fedapay.secret_key' => 'sk_test']);
    app(SettingsService::class)->set('payment.inbound_provider', 'fedapay', 'Passage à FedaPay');
    setFeeRules(['fedapay' => ['rate_bps' => 0, 'fixed_xof' => 0]]);

    $providerDown = true;
    Http::fake(function (Request $request) use (&$providerDown) {
        if ($providerDown) {
            throw new ConnectionException('timeout');
        }

        return str_ends_with($request->url(), '/token')
            ? Http::response(['token' => 't', 'url' => 'https://checkout.fedapay.test/12'])
            : Http::response(['v1/transaction' => ['id' => 12, 'reference' => 'trx_12']], 201);
    });

    startMembershipPayment($this->member)
        ->assertStatus(503)
        ->assertJsonPath('code', 'PAYMENT_PROVIDER_UNAVAILABLE');

    $attempt = PaymentAttempt::query()->sole();
    expect($attempt->status)->toBe(PaymentAttemptStatus::Failed)
        ->and($attempt->failure_reason)->toBe('provider_error')
        ->and($attempt->checkout_url)->toBeNull();

    $providerDown = false;

    startMembershipPayment($this->member)->assertCreated()->assertJsonPath('data.checkout_url', 'https://checkout.fedapay.test/12');
});

it('ignores a webhook about a transaction it does not know', function () {
    $webhook = $this->fake->signedWebhook('transaction.approved', 'fake_unknown');

    sendFakeWebhook($webhook['body'], $webhook['headers'])->assertOk();

    expect(ProviderWebhookEvent::query()->sole()->status)->toBe(WebhookEventStatus::Ignored)
        ->and(SecurityEvent::query()->where('event_type', 'webhook.unknown_transaction')->exists())->toBeTrue();
});

it('records a payment received while the account is suspended without reactivating it', function () {
    $attemptId = startMembershipPayment($this->member)->json('data.id');
    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    app(AccountManager::class)->suspend($superadmin, $this->member, 'Vérification d\'identité en cours');

    settleFakeAttempt($attemptId, ProviderPaymentStatus::Approved)->assertOk();

    $membership = Membership::query()->sole();
    expect($this->member->refresh()->status)->toBe(UserStatus::Suspended)
        ->and($membership->paid_at)->not->toBeNull()
        ->and($membership->status->value)->toBe('pending_payment')
        ->and(SecurityEvent::query()->where('event_type', 'membership.payment_on_inactive_account')->exists())->toBeTrue();

    app(AccountManager::class)->reactivate($superadmin, $this->member, 'Identité confirmée');

    expect($this->member->refresh()->status)->toBe(UserStatus::Active)
        ->and($membership->refresh()->status->value)->toBe('active');
});

it('resolves only the simulator in staging, never the real provider', function () {
    app()->detectEnvironment(fn () => 'staging');

    expect(app(PaymentProviderManager::class)->provider('fake'))->toBeInstanceOf(FakePaymentProvider::class)
        ->and(fn () => app(PaymentProviderManager::class)->provider('fedapay'))->toThrow(PaymentException::class);
});

it('exposes neither the fake provider nor the demo bypass in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(PaymentProviderManager::class)->provider('fake'))->toThrow(PaymentException::class);

    $this->withToken(tokenFor($this->member))->postJson('/api/v1/me/membership/demo-activation')
        ->assertNotFound()
        ->assertJsonPath('code', 'DEMO_MODE_UNAVAILABLE');

    $webhook = $this->fake->signedWebhook('transaction.approved', 'fake_x');
    sendFakeWebhook($webhook['body'], $webhook['headers'])->assertNotFound()->assertJsonPath('code', 'PAYMENT_PROVIDER_UNKNOWN');
});

describe('demo gateway bypass', function () {
    it('is refused while the setting is off', function () {
        $this->withToken(tokenFor($this->member))->postJson('/api/v1/me/membership/demo-activation')
            ->assertNotFound()
            ->assertJsonPath('code', 'DEMO_MODE_UNAVAILABLE');

        expect($this->member->refresh()->status)->toBe(UserStatus::PendingPayment);
    });

    it('activates the account without payment and marks it as a demo activation', function () {
        app(SettingsService::class)->set('platform.demo_gateway_bypass', true, 'Démonstration au conseil d\'administration');

        $this->withToken(tokenFor($this->member))->postJson('/api/v1/me/membership/demo-activation')
            ->assertOk()
            ->assertJsonPath('data.membership.activation_mode', 'demo_gateway_bypass')
            ->assertJsonPath('data.account_status', 'active');

        $membership = Membership::query()->sole();
        expect($this->member->refresh()->status)->toBe(UserStatus::Active)
            ->and($membership->paid_at)->toBeNull()
            ->and(PaymentIntent::query()->count())->toBe(0)
            ->and(AuditEvent::query()->where('action', 'memberships.activated')->sole()->after['activation_mode'])->toBe('demo_gateway_bypass')
            ->and(SecurityEvent::query()->where('event_type', 'membership.demo_gateway_bypass')->exists())->toBeTrue();
    });

    it('approves a fake-provider payment on demand and activates the account', function () {
        $attemptId = startMembershipPayment($this->member)->assertCreated()->json('data.id');

        $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/payments/{$attemptId}/simulate")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        expect($this->member->refresh()->status)->toBe(UserStatus::Active);
    });

    it('refuses to simulate a payment outside testing and staging', function () {
        $attemptId = startMembershipPayment($this->member)->assertCreated()->json('data.id');
        app()->detectEnvironment(fn () => 'production');

        $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/payments/{$attemptId}/simulate")
            ->assertNotFound()
            ->assertJsonPath('code', 'DEMO_MODE_UNAVAILABLE');

        expect($this->member->refresh()->status)->toBe(UserStatus::PendingPayment);
    });
});


it('shows a safe information page for historical fake-checkout links on staging', function () {
    app()->detectEnvironment(fn () => 'staging');

    $this->get('/fake-checkout/fake_01m55fwt1234')
        ->assertOk()
        ->assertSee('mode simulation')
        ->assertSee('aucun paiement');

    app()->detectEnvironment(fn () => 'production');
    $this->get('/fake-checkout/fake_01m55fwt1234')->assertNotFound();
});


it('recovers an owned pending simulated payment after a server cache reset', function () {
    $attemptId = startMembershipPayment($this->member)->assertCreated()->json('data.id');
    $transactionId = (string) PaymentAttempt::query()->findOrFail($attemptId)->provider_transaction_id;
    \Illuminate\Support\Facades\Cache::forget('fake_payment:'.$transactionId);

    $this->withToken(tokenFor($this->member))
        ->postJson("/api/v1/me/payments/{$attemptId}/simulate")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.simulation_available', false);

    expect($this->member->refresh()->status)->toBe(UserStatus::Active)
        ->and(PaymentAttempt::query()->count())->toBe(1);
});

it('never reconstructs unknown fake transactions from arbitrary identifiers', function () {
    expect(fn () => app(FakePaymentProvider::class)->fetchTransaction('fake_unknown'))
        ->toThrow(\App\Domain\Payment\Exceptions\PaymentProviderException::class);
});


it('accepts an international Senegal phone for enabled simulated Wave', function () {
    $this->withToken(tokenFor($this->member))->postJson('/api/v1/me/membership/payments', [
        'method' => 'WAVE',
        'payment_phone' => '+221771234567',
    ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()
        ->assertJsonPath('data.method', 'WAVE')
        ->assertJsonPath('data.provider', 'fake')
        ->assertJsonPath('data.simulation_available', true);
});

it('refuses a mobile money method not enabled for the payment phone country', function () {
    $this->withToken(tokenFor($this->member))->postJson('/api/v1/me/membership/payments', [
        'method' => 'ORANGE_MONEY',
        'payment_phone' => '+22890123456',
    ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(422);

    expect(PaymentAttempt::query()->count())->toBe(0);
});

it('rejects a foreign phone without an enabled operator and prevents a test checkout', function () {
    $this->withToken(tokenFor($this->member))->postJson('/api/v1/me/membership/payments', [
        'method' => 'TMONEY',
        'payment_phone' => '+33612345678',
    ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(422);

    expect(PaymentAttempt::query()->count())->toBe(0);
});


it('accepts simulated card fallback for a non-African phone and never creates a real charge', function () {
    $this->withToken(tokenFor($this->member))->postJson('/api/v1/me/membership/payments', [
        'method' => 'CARD_SIM',
        'payment_phone' => '+33612345678',
    ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()
        ->assertJsonPath('data.method', 'CARD_SIM')
        ->assertJsonPath('data.provider', 'fake')
        ->assertJsonPath('data.simulation_available', true);
});

it('accepts simulated card fallback in an African country without a named mobile money network', function () {
    $this->withToken(tokenFor($this->member))->postJson('/api/v1/me/membership/payments', [
        'method' => 'CARD_SIM',
        'payment_phone' => '+213555123456',
    ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()->assertJsonPath('data.method', 'CARD_SIM');
});

it('refuses simulated card on a phone from an African mobile money market', function () {
    $this->withToken(tokenFor($this->member))->postJson('/api/v1/me/membership/payments', [
        'method' => 'CARD_SIM',
        'payment_phone' => '+22890123456',
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertStatus(422);
    expect(PaymentAttempt::count())->toBe(0);
});
