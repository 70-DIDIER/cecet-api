<?php

namespace App\Domain\Backoffice\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reporting\Http\Controllers\ReportController;
use App\Domain\Reporting\Http\Controllers\SnapshotController;
use App\Domain\Reporting\Jobs\GenerateSnapshotJob;
use App\Domain\Reporting\Models\ExportSnapshot;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV exports and consolidated snapshots. Downloads need a recent step-up and are audited.
 */
class ExportsController
{
    public function index(): View
    {
        return view('backoffice.exports.index', [
            'snapshots' => ExportSnapshot::query()->orderByDesc('version')->limit(50)->get(),
        ]);
    }

    public function export(Request $request, string $type, ReportController $reports, AuditLogger $auditLogger): StreamedResponse
    {
        return $reports->export($request, $type, $auditLogger);
    }

    public function generateSnapshot(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        GenerateSnapshotJob::dispatch($user);

        return redirect()->route('backoffice.exports')->with('status', 'Snapshot demandé : il apparaît dans la liste dès qu\'il est prêt.');
    }

    public function downloadSnapshot(Request $request, string $snapshot, SnapshotController $snapshots, AuditLogger $auditLogger): StreamedResponse
    {
        return $snapshots->download($request, $snapshot, $auditLogger);
    }
}
