<?php

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Settings\Exceptions\SettingException;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Models\PackPeriod;
use App\Domain\Subscription\Models\UserPack;
use App\Domain\Subscription\PeriodRoller;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->member = User::factory()->create();
});

function consumerPlan(): PackPlan
{
    return makePlan(PackFamily::Consommateur, 'C1', [
        'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 12],
        'cumulative' => true,
        'end_of_term' => 'expire',
        'rules' => ['reference_value_xof' => 300000],
        'reason' => 'Grille de recette',
    ]);
}

function setCashback(array $overrides = []): void
{
    app(SettingsService::class)->set('cashback.rules', [
        'enabled' => true,
        'rate_bps' => 500,
        'max_yem' => null,
        'families' => ['CONSOMMATEUR', 'PERSONNEL'],
        'rounding' => 'down',
        ...$overrides,
    ], 'Cashback de recette');
}

it('charges the full price of a Consommateur pack and adds the cashback in YEM, spread over the 12 months', function () {
    setCashback();

    $response = test()->withToken(tokenFor($this->member))
        ->postJson('/api/v1/me/packs', ['plan_id' => consumerPlan()->id], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()
        ->assertJsonPath('data.payment.amount', 300000);

    settleFakeAttempt($response->json('data.payment.id'), ProviderPaymentStatus::Approved)->assertOk();

    $pack = UserPack::query()->sole();
    expect($pack->price_xof)->toBe(300000)
        ->and($pack->cashback_bps)->toBe(500)
        ->and($pack->yem_base)->toBe('461.538456')
        ->and($pack->yem_cashback)->toBe('23.076922')
        ->and($pack->cashback_rule_effective_from)->not->toBeNull();

    $periods = PackPeriod::query()->where('user_pack_id', $pack->id)->orderBy('number')->get();
    expect($periods)->toHaveCount(12)
        ->and($periods->first()->quota)->toBe('40.384614')
        ->and($periods->first()->cashback_quota)->toBe('1.923076')
        ->and($periods->last()->cashback_quota)->toBe('1.923086')
        ->and(array_reduce($periods->pluck('cashback_quota')->all(), fn (string $sum, string $value): string => bcadd($sum, $value, 6), '0'))->toBe('23.076922');

    // The first month is available at once, the bought YEM and the cashback on separate ledger entries.
    expect($pack->account()->first()->available)->toBe('40.384614')
        ->and(QuotaLedgerEntry::query()->where('type', 'credit')->sole()->available_delta)->toBe('38.461538')
        ->and(QuotaLedgerEntry::query()->where('type', 'cashback_credit')->sole()->available_delta)->toBe('1.923076');

    $dashboard = test()->withToken(tokenFor($this->member))->getJson('/api/v1/me/dashboard')->assertOk()->json('data');
    $card = $dashboard['active_packs'][0];

    expect(array_keys(array_intersect_key($card, array_flip(['available', 'reserved', 'credited', 'consumed']))))->toBe(['available', 'reserved', 'credited', 'consumed'])
        ->and($card['credited_purchase'])->toBe('38.461538')
        ->and($card['credited_cashback'])->toBe('1.923076')
        ->and($card['monthly']['limit'])->toBe('40.384614')
        ->and($card['monthly']['remaining'])->toBe('40.384614')
        ->and($card['monthly']['consumed'])->toBe('0.000000')
        ->and($dashboard['yem']['balance'])->toBe('40.384614');
});

it('keeps the cashback calculated at purchase even if the rule changes before the payment is confirmed', function () {
    setCashback(['rate_bps' => 500]);

    $response = test()->withToken(tokenFor($this->member))
        ->postJson('/api/v1/me/packs', ['plan_id' => consumerPlan()->id], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated();

    setCashback(['rate_bps' => 2000]);
    settleFakeAttempt($response->json('data.payment.id'), ProviderPaymentStatus::Approved)->assertOk();

    expect(UserPack::query()->sole()->yem_cashback)->toBe('23.076922');
});

it('offers no cashback when the rule is disabled or the family is not eligible', function () {
    $plan = consumerPlan();
    $pack = subscribeAndPay($this->member, $plan);

    expect($pack->cashback_bps)->toBe(0)
        ->and($pack->yem_cashback)->toBe('0.000000')
        ->and(QuotaLedgerEntry::query()->where('type', 'cashback_credit')->exists())->toBeFalse();

    setCashback(['families' => ['PERSONNEL']]);
    $other = subscribeAndPay(User::factory()->create(), $plan);

    expect($other->cashback_bps)->toBe(0);
});

it('lets a Vendeur sell only once a month has passed since the subscription', function () {
    $pack = subscribeAndPay($this->member, makePlan(PackFamily::Vendeur, 'V9', sellableImmediately: false));

    expect($pack->account()->first()->available)->toBe('0.000000')
        ->and($pack->periods()->first()->starts_at->isSameDay(now()->addMonth()))->toBeTrue();

    app(PeriodRoller::class)->run(now()->addDays(15));
    expect($pack->account()->first()->available)->toBe('0.000000');

    $this->travel(1)->month();
    $this->travel(1)->minute();
    app(PeriodRoller::class)->run(now());

    expect($pack->account()->first()->available)->toBe('125.000000');
});

it('rejects an incoherent cashback rule from the Superadmin', function () {
    expect(fn () => setCashback(['rate_bps' => 10000]))->toThrow(SettingException::class)
        ->and(fn () => setCashback(['rounding' => 'banker']))->toThrow(SettingException::class)
        ->and(fn () => setCashback(['families' => ['AUTRE']]))->toThrow(SettingException::class);
});
