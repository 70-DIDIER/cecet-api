<?php

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\CashbackCalculator;
use App\Support\YemQuantity;

uses(Tests\TestCase::class);

it('preserves purchased YEM and never exceeds pack cashback caps over 5000 randomized scenarios', function () {
    mt_srand(20261020);
    $calculator = new CashbackCalculator(Mockery::mock(SettingsService::class));

    for ($i = 0; $i < 5000; $i++) {
        // Generate exact six-decimal YEM amounts, including sub-unit values.
        $whole = mt_rand(0, 200000);
        $fraction = mt_rand(0, 999999);
        $base = sprintf('%d.%06d', $whole, $fraction);
        $bps = mt_rand(0, 9999);
        $rounding = $i % 2 ? 'half_up' : 'down';
        $cap = $i % 7 === 0 ? sprintf('%d.%06d', mt_rand(0, 2000), mt_rand(0, 999999)) : null;
        $rules = ['enabled' => true, 'rate_bps' => $bps, 'max_yem' => $cap, 'rounding' => $rounding];

        $family = $i % 2 ? PackFamily::Consommateur : PackFamily::Personnel;
        $quote = $calculator->quote($family, $base, $rules, '2026-10-20T00:00:00+00:00');

        expect($quote->yemBase)->toBe(YemQuantity::of($base))
            ->and($quote->yemCashback)->not->toBe('-0.000000')
            ->and(YemQuantity::compare($quote->yemCashback, '0'))->toBeGreaterThanOrEqual(0)
            ->and($quote->yemTotal)->toBe(YemQuantity::add($quote->yemBase, $quote->yemCashback))
            ->and(YemQuantity::compare($quote->yemCashback, $quote->yemBase))->toBeLessThanOrEqual(0);

        if ($cap !== null) {
            expect(YemQuantity::compare($quote->yemCashback, $cap))->toBeLessThanOrEqual(0);
        }

        $parts = mt_rand(1, 60);
        $shares = $calculator->spread($quote->yemTotal, $parts);
        expect($shares)->toHaveCount($parts);
        $sum = YemQuantity::zero();
        foreach ($shares as $share) {
            expect(YemQuantity::compare($share, '0'))->toBeGreaterThanOrEqual(0);
            $sum = YemQuantity::add($sum, $share);
        }
        expect($sum)->toBe($quote->yemTotal);

        // Even forged settings must never authorize Vendeur/Marchand cashback.
        if ($i % 5 === 0) {
            foreach ([PackFamily::Vendeur, PackFamily::Marchand] as $forbidden) {
                expect($calculator->quote($forbidden, $base, $rules)->yemCashback)->toBe('0.000000');
            }
        }
    }
});

it('matches the SuperAdmin example of 100000 XOF at 650 XOF/YEM with 5% bonus', function () {
    $calculator = new CashbackCalculator(Mockery::mock(SettingsService::class));
    $base = bcdiv('100000', '650', YemQuantity::SCALE);
    $quote = $calculator->quote(PackFamily::Consommateur, $base, [
        'enabled' => true, 'rate_bps' => 500, 'max_yem' => null, 'rounding' => 'down',
    ]);
    expect($base)->toBe('153.846153')
        ->and($quote->yemCashback)->toBe('7.692307')
        ->and($quote->yemTotal)->toBe('161.538460')
        ->and(YemQuantity::toXof($quote->yemTotal, 610))->toBe(98538);
});
