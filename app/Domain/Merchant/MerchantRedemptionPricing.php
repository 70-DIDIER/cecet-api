<?php

namespace App\Domain\Merchant;

use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Settings\SettingsService;
use App\Support\YemQuantity;

/**
 * Contractual merchant-redemption pricing. No tariff is embedded in the algorithm:
 * the SuperAdmin controls both source rates and the versioned fee policy.
 *
 * At request creation, take a complete snapshot so subsequent SuperAdmin changes
 * cannot retroactively change amounts due for an already reserved exchange.
 */
final class MerchantRedemptionPricing
{
    public const POLICY_KEY = 'merchant_redemption.pricing';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return array{mode:string, rate_setting_key:string, rate_xof_per_yem:int, fee_bps:int, fixed_fee_xof:int, fee_rounding:string, policy_effective_from:?string}
     */
    public function snapshot(?string $packRateKey = null): array
    {
        $policy = $this->settings->array(self::POLICY_KEY);
        $mode = (string) $policy['mode'];
        $rateKey = $mode === 'sale_minus_fee'
            ? 'rates.sale_xof_per_yem'
            : ($packRateKey ?: 'rates.transfer_xof_per_yem');

        // Version-level overrides are allowed only for known rate settings,
        // never for arbitrary settings such as passwords or unrelated prices.
        if (! in_array($rateKey, ['rates.transfer_xof_per_yem', 'rates.sale_xof_per_yem'], true)) {
            throw new \InvalidArgumentException('Invalid merchant rate configuration.');
        }

        return [
            'mode' => $mode,
            'rate_setting_key' => $rateKey,
            'rate_xof_per_yem' => $this->settings->integer($rateKey),
            'fee_bps' => $mode === 'sale_minus_fee' ? (int) $policy['fee_bps'] : 0,
            'fixed_fee_xof' => $mode === 'sale_minus_fee' ? (int) $policy['fixed_fee_xof'] : 0,
            'fee_rounding' => (string) $policy['fee_rounding'],
            'policy_effective_from' => $this->settings->effectiveFrom(self::POLICY_KEY),
        ];
    }

    /**
     * Financial amounts are always whole FCFA; fee rounding is explicit and deterministic.
     * The percentage applies to the gross FCFA counter-value of the verified received YEM.
     * @param array<string, mixed> $snapshot
     * @return array{gross_amount_xof:int, management_fee_xof:int, net_amount_xof:int}
     */
    public static function quote(string $quantity, array $snapshot): array
    {
        $gross = YemQuantity::toXof($quantity, (int) $snapshot['rate_xof_per_yem']);
        $bps = (int) ($snapshot['fee_bps'] ?? 0);
        $fixed = (int) ($snapshot['fixed_fee_xof'] ?? 0);

        $numerator = bcmul((string) $gross, (string) $bps, 0);
        $rateFee = (int) bcdiv($numerator, '10000', 0);
        if (($snapshot['fee_rounding'] ?? 'down') === 'up' && bcmod($numerator, '10000') !== '0') {
            $rateFee++;
        }

        // Avoid a negative payout: if the configured fee exceeds gross,
        // consume at most the gross; the full fee is never separately owed by the member.
        $fee = min($gross, $rateFee + $fixed);

        return [
            'gross_amount_xof' => $gross,
            'management_fee_xof' => $fee,
            'net_amount_xof' => $gross - $fee,
        ];
    }

    /** Backward compatibility: old merchant requests have no fee snapshot. */
    public static function forExchange(ExchangeRequest $exchange, string $quantity): array
    {
        $snapshot = $exchange->pricing_snapshot ?? [
            'rate_xof_per_yem' => $exchange->rate_xof_per_yem,
            'fee_bps' => 0,
            'fixed_fee_xof' => 0,
            'fee_rounding' => 'down',
        ];

        return self::quote($quantity, $snapshot);
    }
}
