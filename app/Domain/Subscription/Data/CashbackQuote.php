<?php

namespace App\Domain\Subscription\Data;

use Carbon\CarbonImmutable;

/**
 * Frozen result of a cashback calculation. The amount paid never changes: the cashback is extra YEM.
 */
final readonly class CashbackQuote
{
    public function __construct(
        public string $yemBase,
        public string $yemCashback,
        public string $yemTotal,
        public int $rateBps,
        public string $rounding,
        public ?string $ruleEffectiveFrom,
        public CarbonImmutable $calculatedAt,
    ) {}

    public function hasCashback(): bool
    {
        return bccomp($this->yemCashback, '0', 6) > 0;
    }
}
