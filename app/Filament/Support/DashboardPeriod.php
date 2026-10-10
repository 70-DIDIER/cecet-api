<?php

namespace App\Filament\Support;

use Carbon\CarbonImmutable;

/**
 * The period chosen on the dashboard: the last N days up to and including today, as the half-open interval used by
 * the reporting queries.
 */
final class DashboardPeriod
{
    public const DEFAULT_DAYS = 30;

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public static function days(?array $filters): int
    {
        $days = (int) ($filters['days'] ?? self::DEFAULT_DAYS);

        return in_array($days, [7, 30, 90], true) ? $days : self::DEFAULT_DAYS;
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function bounds(?array $filters): array
    {
        $to = CarbonImmutable::tomorrow();

        return [$to->subDays(self::days($filters)), $to];
    }
}
