<?php

namespace App\Domain\Reporting\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reporting\Jobs\GenerateSnapshotJob;
use App\Domain\Reporting\Models\ExportSnapshot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consolidated snapshots: history, on-demand generation, download of an archive (latest or any kept version).
 */
class SnapshotController
{
    public function index(): JsonResponse
    {
        $snapshots = ExportSnapshot::query()->orderByDesc('version')->limit(200)->get();

        return response()->json([
            'data' => $snapshots->map(fn (ExportSnapshot $snapshot): array => $snapshot->toSummary())->all(),
            'meta' => ['latest_ready' => $snapshots->firstWhere('status', ExportSnapshot::READY)?->id],
        ]);
    }

    /**
     * Ask for a snapshot now; it is generated in the background (202).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        GenerateSnapshotJob::dispatch($user);

        return response()->json(['data' => ['queued' => true]], 202);
    }

    /**
     * Download an archive ("latest" for the most recent one). The archive's SHA-256 is sent in X-Content-SHA256 so
     * that the copy can be checked offline. Every download is audited.
     */
    public function download(Request $request, string $snapshot, AuditLogger $auditLogger): StreamedResponse
    {
        $model = $snapshot === 'latest'
            ? ExportSnapshot::query()->where('status', ExportSnapshot::READY)->orderByDesc('version')->firstOrFail()
            : ExportSnapshot::query()->whereKey($snapshot)->firstOrFail();

        abort_unless($model->status === ExportSnapshot::READY && $model->path !== null && Storage::disk('local')->exists($model->path), 404);

        $auditLogger->record('reports.snapshot_downloaded', $model, metadata: ['version' => $model->version, 'sha256' => $model->sha256]);

        return Storage::disk('local')->download($model->path, basename($model->path), [
            'Content-Type' => 'application/zip',
            'X-Content-SHA256' => (string) $model->sha256,
        ]);
    }
}
