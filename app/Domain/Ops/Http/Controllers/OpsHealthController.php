<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Ops\OpsMonitor;
use App\Domain\Reporting\Models\ExportSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Health board of the platform for its supervisors: the current state of each check (without raising alerts),
 * queue backlog and the latest snapshot.
 */
class OpsHealthController
{
    public function __invoke(OpsMonitor $monitor): JsonResponse
    {
        $checks = $monitor->check();
        $latest = ExportSnapshot::query()->orderByDesc('version')->first();

        return response()->json(['data' => [
            'status' => collect($checks)->contains(fn (array $check): bool => ! $check['ok'] && $check['severity'] === 'critical')
                ? 'critical'
                : (collect($checks)->contains(fn (array $check): bool => ! $check['ok']) ? 'degraded' : 'ok'),
            'checks' => $checks,
            'queue' => [
                'pending_jobs' => DB::table('jobs')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
            ],
            'latest_snapshot' => $latest?->toSummary(),
            'checked_at' => now()->toIso8601ZuluString(),
        ]]);
    }
}
