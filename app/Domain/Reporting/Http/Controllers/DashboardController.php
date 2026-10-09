<?php

namespace App\Domain\Reporting\Http\Controllers;

use App\Domain\Ops\OpsMonitor;
use App\Domain\Reporting\ReportingQueries;
use App\Domain\Reporting\ReportPeriod;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Home page of the back-office. Each block is returned according to the permissions of the signed-in account:
 * validators get what awaits their decision, finance and administrators the figures of the period, supervisors
 * the platform health. A block the account may not see is null.
 */
class DashboardController
{
    public function __construct(
        private readonly ReportingQueries $queries,
        private readonly OpsMonitor $monitor,
    ) {}

    /**
     * Figures of the period (default: the last 30 days), with the change of collected amounts against the
     * previous period of the same length, the daily series, the latest payments and the pending validations.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        [$from, $to] = ReportPeriod::fromRequest($request, withMonth: false);
        $canReport = $user->can('reports.view');
        $pending = $this->queries->pendingValidations();

        $summary = $canReport ? $this->queries->summary($from, $to) : null;
        $trend = null;

        if ($summary !== null) {
            $length = (int) $from->diffInDays($to);
            $previous = $this->queries->summary($from->subDays($length), $from)['kpis']['total_collected_xof'];
            $trend = $this->trend($summary['kpis']['total_collected_xof'], (int) $previous);
        }

        return response()->json(['data' => [
            'period' => ['from' => $from->toIso8601ZuluString(), 'to' => $to->toIso8601ZuluString()],
            'summary' => $summary,
            'trend' => $trend,
            'series' => $canReport ? $this->queries->dailySeries($from, $to) : null,
            'recent_payments' => $canReport ? $this->queries->recentPayments(6) : null,
            'overview' => $canReport ? $this->queries->overview() : null,
            'pending_validations' => [
                'payments' => $user->can('payments.validate_manual') ? $pending['payments'] : null,
                'yem' => $user->can('yem_validations.view') ? $pending['yem'] + $pending['merchant'] : null,
            ],
            'health' => $user->can('security_events.view') ? $this->monitor->check() : null,
        ]]);
    }

    /**
     * @return array{direction: string, percent: int}|null change against the previous period of the same length
     */
    private function trend(int $current, int $previous): ?array
    {
        if ($previous <= 0) {
            return null;
        }

        $percent = (int) round(($current - $previous) * 100 / $previous);

        return ['direction' => $percent >= 0 ? 'up' : 'down', 'percent' => abs($percent)];
    }
}
