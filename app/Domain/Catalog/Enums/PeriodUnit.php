<?php

namespace App\Domain\Catalog\Enums;

use Carbon\CarbonImmutable;

enum PeriodUnit: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /**
     * Instant reached after $count units, always computed from the same origin so that
     * month ends do not drift (Jan 31 + 1 month = Feb 28/29, + 2 months = Mar 31).
     */
    public function addTo(CarbonImmutable $origin, int $count): CarbonImmutable
    {
        return match ($this) {
            self::Day => $origin->addDays($count),
            self::Week => $origin->addWeeks($count),
            self::Month => $origin->addMonthsNoOverflow($count),
        };
    }
}
