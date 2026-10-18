<?php

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\PackVersionValidator;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\CashbackCalculator;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

function sampleCashback(): array
{
    return ['enabled' => true, 'rate_bps' => 500, 'max_yem' => null, 'rounding' => 'down'];
}

it('calculates five percent bonus on immutable consumer pack terms without changing paid YEM', function () {
    $settings = Mockery::mock(SettingsService::class);
    $settings->shouldNotReceive('array');
    $quote = (new CashbackCalculator($settings))->quote(
        PackFamily::Consommateur, '100.000000', sampleCashback(), '2026-10-18T10:00:00+00:00'
    );
    expect($quote->yemBase)->toBe('100.000000')
        ->and($quote->yemCashback)->toBe('5.000000')
        ->and($quote->yemTotal)->toBe('105.000000')
        ->and($quote->rateBps)->toBe(500);
});

it('allows personnel cashback per topup but rejects bonus for merchant and seller even with forged terms', function () {
    $settings = Mockery::mock(SettingsService::class);
    $calculator = new CashbackCalculator($settings);
    expect($calculator->quote(PackFamily::Personnel, '20.000000', sampleCashback())->yemCashback)->toBe('1.000000')
        ->and($calculator->quote(PackFamily::Vendeur, '20.000000', sampleCashback())->yemCashback)->toBe('0.000000')
        ->and($calculator->quote(PackFamily::Marchand, '20.000000', sampleCashback())->yemCashback)->toBe('0.000000');
});

it('accepts pack-scoped cashback only on consumer and personnel version rules', function () {
    $settings = Mockery::mock(SettingsService::class);
    $validator = new PackVersionValidator($settings);

    $base = [
        'price_xof' => 100000,
        'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 12],
        'quota_per_period' => '50',
        'cumulative' => false,
        'end_of_term' => 'expire',
        'reason' => 'Conditions de test explicites',
        'rules' => ['cashback' => sampleCashback()],
    ];

    expect($validator->validate(PackFamily::Consommateur, $base)['rules']['cashback']['rate_bps'])->toBe(500);
    expect($validator->validate(PackFamily::Personnel, [
        'price_xof' => 0,
        'reason' => 'Ouverture de pack personnel',
        'rules' => ['min_topup_xof' => 5000, 'cashback' => sampleCashback()],
    ])['rules']['cashback']['enabled'])->toBeTrue();

    foreach ([PackFamily::Vendeur, PackFamily::Marchand] as $family) {
        expect(fn () => $validator->validate($family, $base))->toThrow(ValidationException::class);
    }
});

it('rejects malformed cashback bonuses before a pack version is persisted', function () {
    $validator = new PackVersionValidator(Mockery::mock(SettingsService::class));
    $base = [
        'price_xof' => 100000,
        'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 12],
        'quota_per_period' => '50',
        'cumulative' => false,
        'end_of_term' => 'expire',
        'reason' => 'Conditions de test explicites',
        'rules' => ['cashback' => array_merge(sampleCashback(), ['rate_bps' => 10000])],
    ];
    expect(fn () => $validator->validate(PackFamily::Consommateur, $base))->toThrow(ValidationException::class);
    $base['rules']['cashback'] = array_merge(sampleCashback(), ['bonus_fiat_xof' => 1000]);
    expect(fn () => $validator->validate(PackFamily::Consommateur, $base))->toThrow(ValidationException::class);
});
