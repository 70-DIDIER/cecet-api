<?php

namespace App\Domain\Backoffice\Http\Controllers;

use App\Domain\Ops\OpsMonitor;
use App\Domain\Reporting\ReportingQueries;
use App\Domain\Reporting\ReportPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Home of the back-office. Each block is shown according to the permissions of the signed-in account: validators
 * see what awaits their decision, finance and administrators see the figures of the period.
 */
class DashboardController
{
    public function __construct(
        private readonly ReportingQueries $queries,
        private readonly OpsMonitor $monitor,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        [$from, $to] = $this->period($request);
        $canReport = $user->can('reports.view');
        $canValidatePayments = $user->can('payments.validate_manual');
        $canValidateYem = $user->can('yem_validations.view');

        $summary = $canReport ? $this->queries->summary($from, $to) : null;
        $previous = null;

        if ($canReport) {
            $length = (int) $from->diffInDays($to);
            $previous = $this->queries->summary($from->subDays($length), $from)['kpis']['total_collected_xof'];
        }

        $pending = $this->queries->pendingValidations();

        return view('backoffice.dashboard', [
            'from' => $from,
            'lastDay' => $to->subDay(),
            'summary' => $summary,
            'trend' => $summary === null ? null : $this->trend($summary['kpis']['total_collected_xof'], (int) $previous),
            'series' => $canReport ? $this->queries->dailySeries($from, $to) : null,
            'recent' => $canReport ? $this->queries->recentPayments(6) : [],
            'pending' => [
                'payments' => $canValidatePayments ? $pending['payments'] : null,
                'yem' => $canValidateYem ? $pending['yem'] + $pending['merchant'] : null,
            ],
            'overview' => $canReport ? $this->queries->overview() : null,
            'health' => $user->can('security_events.view') ? $this->monitor->check() : null,
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} first day (included) and the day after the last one
     */
    private function period(Request $request): array
    {
        return ReportPeriod::fromRequest($request, withMonth: false);
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
