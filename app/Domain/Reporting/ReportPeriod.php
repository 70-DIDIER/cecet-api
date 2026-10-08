<?php

namespace App\Domain\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The period of a report: a calendar month (YYYY-MM), or from/to days (both included), default the last 30 days.
 * Returned as [first instant, instant after the last day] so that queries use a half-open interval.
 */
final class ReportPeriod
{
    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 366;

    /**
     * Validation rules of the period inputs.
     *
     * @return array<string, list<string>>
     */
    public static function rules(bool $withMonth = true): array
    {
        return [
            ...($withMonth ? ['month' => ['nullable', 'date_format:Y-m', 'prohibits:from,to']] : []),
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function fromRequest(Request $request, bool $withMonth = true): array
    {
        /** @var array{month?: string|null, from?: string|null, to?: string|null} $validated */
        $validated = $request->validate(self::rules($withMonth));

        if (isset($validated['month'])) {
            $from = CarbonImmutable::createFromFormat('!Y-m', $validated['month']) ?: CarbonImmutable::now()->startOfMonth();

            return [$from, $from->addMonth()];
        }

        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'])->addDay()->startOfDay() : CarbonImmutable::tomorrow();
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'])->startOfDay() : $to->subDays(self::DEFAULT_DAYS);

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS);
        }

        return [$from, $to];
    }
}
