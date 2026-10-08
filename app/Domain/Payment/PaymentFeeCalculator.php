<?php

namespace App\Domain\Payment;

use App\Domain\Payment\Data\FeeBreakdown;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Settings\SettingsService;
use DateTimeInterface;

/**
 * Computes the provider fees added to what a customer pays, so that the cooperative receives its net amount.
 *
 * The fee rule of each provider (percentage in basis points, fixed part, rounding) is a versioned setting.
 * The total is the smallest amount T, rounded up to the configured unit, such that
 * T - (T * rate + fixed) >= net: the provider's own fee on T never eats into the net amount.
 * A provider without a configured rule is refused rather than silently charged at zero.
 */
class PaymentFeeCalculator
{
    private const BASIS_POINTS = 10000;

    public function __construct(private readonly SettingsService $settings) {}

    public function forNet(string $provider, int $netAmount, ?DateTimeInterface $at = null): FeeBreakdown
    {
        if (! $this->settings->boolean('payment.customer_bears_fees', $at)) {
            return new FeeBreakdown($netAmount, 0, $netAmount, ['customer_bears_fees' => false]);
        }

        $rule = $this->settings->array('payment.customer_fees', $at)[$provider] ?? null;

        if (! is_array($rule) || ! is_int($rule['rate_bps'] ?? null) || ! is_int($rule['fixed_xof'] ?? null)) {
            throw PaymentException::feesNotConfigured($provider);
        }

        $rate = $rule['rate_bps'];
        $fixed = $rule['fixed_xof'];
        $rounding = is_int($rule['rounding_xof'] ?? null) && $rule['rounding_xof'] >= 1 ? $rule['rounding_xof'] : 1;

        $divisor = self::BASIS_POINTS - $rate;
        $gross = intdiv(($netAmount + $fixed) * self::BASIS_POINTS + $divisor - 1, $divisor);
        $total = intdiv($gross + $rounding - 1, $rounding) * $rounding;

        return new FeeBreakdown($netAmount, $total - $netAmount, $total, [
            'customer_bears_fees' => true,
            'provider' => $provider,
            'rate_bps' => $rate,
            'fixed_xof' => $fixed,
            'rounding_xof' => $rounding,
        ]);
    }
}
