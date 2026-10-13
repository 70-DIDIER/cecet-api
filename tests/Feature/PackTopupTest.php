<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Enums\PackTopupStatus;
use App\Domain\Subscription\Models\PackTopup;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->member = User::factory()->create();
    $this->perso = subscribeAndPay($this->member, makePlan(PackFamily::Personnel, 'PERSO', personnelTerms([
        'price_xof' => 0,
        'rules' => ['min_topup_xof' => 5000],
    ])));
});

function topUp(User $member, UserPack $pack, int $amount, ?string $method = null): TestResponse
{
    return test()->withToken(tokenFor($member))
        ->postJson("/api/v1/me/packs/{$pack->id}/topups", array_filter(['amount_xof' => $amount, 'method' => $method], fn ($value) => $value !== null), ['Idempotency-Key' => (string) Str::ulid()]);
}

function enableCashback(int $rateBps = 500, array $overrides = []): void
{
    app(SettingsService::class)->set('cashback.rules', [
        'enabled' => true,
        'rate_bps' => $rateBps,
        'max_yem' => null,
        'families' => ['CONSOMMATEUR', 'PERSONNEL'],
        'rounding' => 'down',
        ...$overrides,
    ], 'Cashback de recette');
}

it('credits the YEM bought at the sale rate once the provider confirms the payment, without any cashback by default', function () {
    $response = topUp($this->member, $this->perso, 5000)
        ->assertCreated()
        ->assertJsonPath('data.topup.status', 'pending_payment')
        ->assertJsonPath('data.topup.amount_xof', 5000)
        ->assertJsonPath('data.topup.cashback_bps', 0)
        ->assertJsonPath('data.topup.rate_xof_per_yem', 650)
        ->assertJsonPath('data.topup.yem_cashback', '0.000000')
        ->assertJsonPath('data.topup.quantity', '7.692307')
        ->assertJsonPath('data.payment.mode', 'psp');

    expect($this->perso->account()->first()->available)->toBe('0.000000');

    settleFakeAttempt($response->json('data.payment.id'), ProviderPaymentStatus::Approved)->assertOk();

    expect($this->perso->account()->first()->available)->toBe('7.692307')
        ->and(PackTopup::query()->sole()->status)->toBe(PackTopupStatus::Credited)
        ->and(QuotaLedgerEntry::query()->where('reason', 'Rechargement Perso')->sole()->available_delta)->toBe('7.692307')
        ->and(QuotaLedgerEntry::query()->where('type', 'cashback_credit')->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'packs.topup_credited')->exists())->toBeTrue();

    // A webhook replayed later credits nothing more.
    settleFakeAttempt($response->json('data.payment.id'), ProviderPaymentStatus::Approved);
    expect($this->perso->account()->first()->available)->toBe('7.692307');

    $this->withToken(tokenFor($this->member))->getJson("/api/v1/me/packs/{$this->perso->id}/topups")
        ->assertOk()
        ->assertJsonPath('data.0.status', 'credited');
});

it('appears as "Rechargement Perso" in the validation queue when paid in cash', function () {
    $attemptId = topUp($this->member, $this->perso, 10000, 'CASH')->assertCreated()->json('data.payment.id');
    $validator = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();

    $this->withToken(tokenFor($validator))->getJson('/api/v1/validations/queue')
        ->assertJsonPath('data.0.label', 'Rechargement Perso')
        ->assertJsonPath('data.0.amount_xof', 10000);

    $this->withToken(tokenFor($validator, withStepUp: true))
        ->postJson("/api/v1/validations/payments/{$attemptId}/approve", ['received_amount' => 10000, 'reference' => 'Reçu 0043'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    expect($this->perso->account()->first()->available)->toBe('15.384615');
});

it('refuses an amount below the minimum and packs other than an active Personnel pack', function () {
    topUp($this->member, $this->perso, 4999)->assertStatus(422)->assertJsonPath('code', 'TOPUP_BELOW_MINIMUM')->assertJsonPath('details.min_topup_xof', 5000);

    $vendeur = subscribeAndPay($this->member, makePlan());
    topUp($this->member, $vendeur, 10000)->assertStatus(409)->assertJsonPath('code', 'TOPUP_NOT_AVAILABLE');
});

it('resumes the pending top-up for the same amount and replaces an undeclared one for another amount', function () {
    $first = topUp($this->member, $this->perso, 5000, 'CASH')->json('data');

    topUp($this->member, $this->perso, 5000, 'CASH')->assertOk()->assertJsonPath('data.topup.id', $first['topup']['id']);

    $second = topUp($this->member, $this->perso, 8000, 'CASH')->assertCreated()->json('data');

    expect($second['topup']['id'])->not->toBe($first['topup']['id'])
        ->and(PackTopup::query()->findOrFail($first['topup']['id'])->status)->toBe(PackTopupStatus::Canceled)
        ->and(PaymentAttempt::query()->findOrFail($first['payment']['id'])->status)->toBe(PaymentAttemptStatus::Canceled);
});

it('does not replace a top-up whose payment was declared or is open at the provider', function () {
    $declared = topUp($this->member, $this->perso, 5000, 'CASH')->json('data.payment.id');
    $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/payments/{$declared}/declare", ['reference' => 'Dépôt Flooz 12/10'])->assertOk();

    topUp($this->member, $this->perso, 8000, 'CASH')->assertStatus(409)->assertJsonPath('code', 'TOPUP_IN_PROGRESS');

    $other = User::factory()->create();
    $pack = subscribeAndPay($other, UserPack::query()->findOrFail($this->perso->id)->plan);
    topUp($other, $pack, 5000)->assertCreated();
    topUp($other, $pack, 6000)->assertStatus(409)->assertJsonPath('code', 'TOPUP_IN_PROGRESS');
});

it('snapshots the rate of the day on the top-up', function () {
    $response = topUp($this->member, $this->perso, 6500);
    app(SettingsService::class)->set('rates.sale_xof_per_yem', 700, 'Révision du taux de vente');

    settleFakeAttempt($response->json('data.payment.id'), ProviderPaymentStatus::Approved)->assertOk();

    expect($this->perso->account()->first()->available)->toBe('10.000000');
});

it('adds the cashback in YEM on top without ever reducing the amount paid, and traces it apart from the YEM bought', function () {
    enableCashback(500);

    $response = topUp($this->member, $this->perso, 5000)
        ->assertCreated()
        ->assertJsonPath('data.topup.amount_xof', 5000)
        ->assertJsonPath('data.topup.cashback_bps', 500)
        ->assertJsonPath('data.topup.yem_base', '7.692307')
        ->assertJsonPath('data.topup.yem_cashback', '0.384615')
        ->assertJsonPath('data.topup.quantity', '8.076922')
        ->assertJsonPath('data.payment.amount', 5000);

    settleFakeAttempt($response->json('data.payment.id'), ProviderPaymentStatus::Approved)->assertOk();

    expect($this->perso->account()->first()->available)->toBe('8.076922')
        ->and(QuotaLedgerEntry::query()->where('type', 'credit')->where('reason', 'Rechargement Perso')->sole()->available_delta)->toBe('7.692307')
        ->and(QuotaLedgerEntry::query()->where('type', 'cashback_credit')->sole()->available_delta)->toBe('0.384615');
});

it('never recalculates a top-up already requested when the cashback rule changes afterwards', function () {
    enableCashback(500);
    $response = topUp($this->member, $this->perso, 5000)->assertCreated();

    enableCashback(2000);
    settleFakeAttempt($response->json('data.payment.id'), ProviderPaymentStatus::Approved)->assertOk();

    $topup = PackTopup::query()->sole();
    expect($topup->cashback_bps)->toBe(500)
        ->and($topup->yem_cashback)->toBe('0.384615')
        ->and($topup->cashback_rule_effective_from)->not->toBeNull()
        ->and($this->perso->account()->first()->available)->toBe('8.076922');
});

it('caps the cashback and ignores families that are not eligible', function () {
    enableCashback(1000, ['max_yem' => '0.5']);
    topUp($this->member, $this->perso, 65000)->assertCreated()->assertJsonPath('data.topup.yem_cashback', '0.500000');

    enableCashback(1000, ['families' => ['CONSOMMATEUR']]);
    $other = User::factory()->create();
    $pack = subscribeAndPay($other, $this->perso->plan);
    topUp($other, $pack, 6500)->assertCreated()->assertJsonPath('data.topup.cashback_bps', 0)->assertJsonPath('data.topup.quantity', '10.000000');
});

it('keeps top-ups private to the pack owner', function () {
    $stranger = User::factory()->create();

    topUp($stranger, $this->perso, 5000)->assertNotFound();
    $this->withToken(tokenFor($stranger))->getJson("/api/v1/me/packs/{$this->perso->id}/topups")->assertNotFound();
});
