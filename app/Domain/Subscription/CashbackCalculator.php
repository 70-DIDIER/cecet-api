<?php

namespace App\Domain\Subscription;

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Data\CashbackQuote;
use App\Support\YemQuantity;
use Carbon\CarbonImmutable;

/**
 * yem_cashback = yem_base x rate / 100, capped, rounded as configured. The rule (enabled, rate, cap, families,
 * rounding) comes from the versioned Superadmin setting "cashback.rules"; nothing is hard-coded here.
 */
class CashbackCalculator
{
    private const RULES_KEY = 'cashback.rules';

    private const BASIS_POINTS = '10000';

    public function __construct(private readonly SettingsService $settings) {}

    /** @param array<string,mixed>|null $packRule Immutable version-local rule; null retains historic global setting. */
    public function quote(PackFamily $family, string $yemBase, ?array $packRule = null, ?string $versionEffectiveFrom = null): CashbackQuote
    {
        $now = CarbonImmutable::now();
        $base = YemQuantity::of($yemBase);
        $rules = $packRule ?? $this->settings->array(self::RULES_KEY);

        $rounding = ($rules['rounding'] ?? 'down') === 'half_up' ? 'half_up' : 'down';
        $rateBps = is_int($rules['rate_bps'] ?? null) ? $rules['rate_bps'] : 0;
        $families = array_map('strval', is_array($rules['families'] ?? null) ? $rules['families'] : []);
        // Defense in depth: ignore legacy or corrupted settings granting cashback
        // to Vendeur/Marchand. Eligibility is a fixed product policy, not a UI hint.
        $eligibleFamily = in_array($family, [PackFamily::Consommateur, PackFamily::Personnel], true);
        $applies = $eligibleFamily && ($rules['enabled'] ?? false) === true && $rateBps > 0
            && ($packRule !== null || in_array($family->value, $families, true));

        $cashback = YemQuantity::zero();

        if ($applies) {
            $cashback = $this->percentage($base, $rateBps, $rounding);
            $cap = $rules['max_yem'] ?? null;

            if (is_numeric($cap) && YemQuantity::compare($cashback, YemQuantity::of((string) $cap)) > 0) {
                $cashback = YemQuantity::of((string) $cap);
            }
        }

        return new CashbackQuote(
            yemBase: $base,
            yemCashback: $cashback,
            yemTotal: YemQuantity::add($base, $cashback),
            rateBps: $applies ? $rateBps : 0,
            rounding: $rounding,
            ruleEffectiveFrom: $packRule !== null
                ? ($versionEffectiveFrom ?? $now->toIso8601String())
                : $this->settings->effectiveFrom(self::RULES_KEY, $now),
            calculatedAt: $now,
        );
    }

    /**
     * Splits an amount of YEM over $parts periods; the last one absorbs the rounding remainder.
     *
     * @return list<string>
     */
    public function spread(string $total, int $parts): array
    {
        $share = bcdiv(YemQuantity::of($total), (string) $parts, YemQuantity::SCALE);
        $shares = array_fill(0, $parts, $share);
        $shares[$parts - 1] = YemQuantity::sub(YemQuantity::of($total), YemQuantity::times($share, $parts - 1));

        return $shares;
    }

    private function percentage(string $base, int $rateBps, string $rounding): string
    {
        $exact = bcmul($base, (string) $rateBps, YemQuantity::SCALE);

        if ($rounding === 'half_up') {
            return bcadd(bcdiv($exact, self::BASIS_POINTS, YemQuantity::SCALE + 1), '0.0000005', YemQuantity::SCALE);
        }

        return bcdiv($exact, self::BASIS_POINTS, YemQuantity::SCALE);
    }
}
