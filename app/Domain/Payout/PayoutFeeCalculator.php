<?php

namespace App\Domain\Payout;

use App\Domain\Payout\Exceptions\PayoutException;
use App\Domain\Settings\SettingsService;

/**
 * Decides how much is actually transferred for a payout item (open question PO-09).
 *
 * - "cooperative" (default): the beneficiary receives the full amount; the provider fee is an estimated cost
 *   for the cooperative (0 if no rule is configured).
 * - "beneficiary": the transfer is reduced so that transfer + provider fee never exceeds the amount owed;
 *   a provider without a configured fee rule is refused rather than guessed.
 */
class PayoutFeeCalculator
{
    private const BASIS_POINTS = 10000;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return array{transfer_amount: int, fee_amount: int}
     */
    public function forItem(string $provider, int $amount): array
    {
        $rule = $this->settings->array('payout.provider_fees')[$provider] ?? null;
        $rate = is_array($rule) && is_int($rule['rate_bps'] ?? null) ? $rule['rate_bps'] : null;
        $fixed = is_array($rule) && is_int($rule['fixed_xof'] ?? null) ? $rule['fixed_xof'] : null;

        if ($this->settings->string('payout.fees_borne_by') !== 'beneficiary') {
            $estimate = $rate === null || $fixed === null ? 0 : intdiv($amount * $rate + self::BASIS_POINTS - 1, self::BASIS_POINTS) + $fixed;

            return ['transfer_amount' => $amount, 'fee_amount' => min($estimate, $amount)];
        }

        if ($rate === null || $fixed === null) {
            throw PayoutException::feesNotConfigured($provider);
        }

        $transfer = max(0, intdiv(($amount - $fixed) * self::BASIS_POINTS, self::BASIS_POINTS + $rate));

        return ['transfer_amount' => $transfer, 'fee_amount' => $amount - $transfer];
    }
}
