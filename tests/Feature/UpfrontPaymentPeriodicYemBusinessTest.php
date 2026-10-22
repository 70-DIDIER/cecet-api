<?php

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Payment\Enums\PaymentIntentStatus;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Quota\Models\QuotaAccount;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Models\PackPeriod;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedCatalogs();
    Mail::fake();
});

it('requires a single full payment then credits only the due YEM periods for PF Consommateur and PM Marchand', function (): void {
    foreach ([
        [PackFamily::Consommateur, 'CT1', false],
        [PackFamily::Marchand, 'MT1', true],
    ] as [$family, $code, $legal]) {
        $member = $legal ? User::factory()->legalPerson()->create() : User::factory()->create();
        $plan = makePlan($family, $code, periodicTerms([
            'price_xof' => 120000,
            'quota_per_period' => '40',
            'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 3],
        ]));

        $subscription = $this->withToken(tokenFor($member))
            ->postJson('/api/v1/me/packs', ['plan_id' => $plan->id], ['Idempotency-Key' => (string) Str::ulid()])
            ->assertCreated()
            ->assertJsonPath('data.user_pack.status', 'pending_payment')
            ->assertJsonPath('data.user_pack.price_xof', 120000)
            ->assertJsonPath('data.user_pack.balance', null);

        $packId = $subscription->json('data.user_pack.id');
        $attemptId = $subscription->json('data.payment.id');
        $intent = PaymentIntent::query()->where('user_id', $member->id)->where('payable_id', $packId)->sole();

        expect($intent->amount)->toBe(120000)
            ->and($intent->status)->toBe(PaymentIntentStatus::Open)
            ->and($intent->total_amount)->toBe($subscription->json('data.payment.amount'))
            ->and(QuotaAccount::query()->where('user_pack_id', $packId)->count())->toBe(0)
            ->and(PackPeriod::query()->where('user_pack_id', $packId)->count())->toBe(0)
            ->and(QuotaLedgerEntry::query()->where('user_pack_id', $packId)->count())->toBe(0);

        $this->artisan('packs:roll-periods')->assertSuccessful();
        expect(QuotaLedgerEntry::query()->where('user_pack_id', $packId)->count())->toBe(0);

        settleFakeAttempt($attemptId, ProviderPaymentStatus::Approved)->assertOk();
        $pack = UserPack::query()->with(['account', 'periods'])->findOrFail($packId);

        expect($intent->refresh()->status)->toBe(PaymentIntentStatus::Succeeded)
            ->and($pack->status)->toBe(UserPackStatus::Active)
            ->and($pack->paid_at)->not->toBeNull()
            ->and($pack->periods)->toHaveCount(3)
            ->and($pack->account->available)->toBe('40.000000')
            ->and(QuotaLedgerEntry::query()->where('user_pack_id', $packId)->count())->toBe(1)
            ->and($pack->periods->filter(fn ($period) => $period->credited_at !== null))->toHaveCount(1);

        settleFakeAttempt($attemptId, ProviderPaymentStatus::Approved)->assertOk();
        expect(QuotaLedgerEntry::query()->where('user_pack_id', $packId)->count())->toBe(1)
            ->and(PaymentIntent::query()->where('user_id', $member->id)->where('payable_id', $packId)->count())->toBe(1);

        $this->travel(1)->month();
        $this->travel(1)->day();
        $this->artisan('packs:roll-periods')->assertSuccessful();
        expect($pack->account()->firstOrFail()->available)->toBe('80.000000')
            ->and(QuotaLedgerEntry::query()->where('user_pack_id', $packId)->count())->toBe(2);

        $this->artisan('packs:roll-periods')->assertSuccessful();
        expect(QuotaLedgerEntry::query()->where('user_pack_id', $packId)->count())->toBe(2)
            ->and(PaymentIntent::query()->where('user_id', $member->id)->where('payable_id', $packId)->count())->toBe(1);

        $this->travelBack();
    }
});

it('rejects partial payment without activating pack or generating a YEM calendar', function (): void {
    $member = User::factory()->legalPerson()->create();
    $plan = makePlan(PackFamily::Marchand, 'MT2', periodicTerms(['price_xof' => 120000]));
    $subscription = $this->withToken(tokenFor($member))
        ->postJson('/api/v1/me/packs', ['plan_id' => $plan->id], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated();

    $packId = $subscription->json('data.user_pack.id');
    settleFakeAttempt($subscription->json('data.payment.id'), ProviderPaymentStatus::Approved, amount: 60000)->assertOk();

    expect(UserPack::query()->findOrFail($packId)->status)->toBe(UserPackStatus::PendingPayment)
        ->and(PaymentIntent::query()->where('payable_id', $packId)->sole()->status)->toBe(PaymentIntentStatus::Open)
        ->and(QuotaAccount::query()->where('user_pack_id', $packId)->count())->toBe(0)
        ->and(PackPeriod::query()->where('user_pack_id', $packId)->count())->toBe(0)
        ->and(QuotaLedgerEntry::query()->where('user_pack_id', $packId)->count())->toBe(0);
});
