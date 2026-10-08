<?php

namespace App\Domain\Reporting\Http\Controllers;

use App\Domain\Reporting\Imports\MemberImportService;
use App\Domain\Reporting\Models\ExportSnapshot;
use App\Domain\Reporting\Models\ImportJob;
use App\Domain\Reporting\Models\ImportRow;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlled re-import: stage a file, review its diff, then approve (another person) or cancel.
 */
class ImportController
{
    public function __construct(private readonly MemberImportService $imports) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => ImportJob::query()->orderByDesc('created_at')->limit(100)->get()->map(fn (ImportJob $job): array => $job->toSummary())->all()]);
    }

    /**
     * Stage the member file of a snapshot, edited outside the platform. Nothing is changed: the response is the
     * diff to review (changes, conflicts, errors).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'base_snapshot_id' => ['required', 'string', 'ulid'],
        ]);

        $base = ExportSnapshot::query()->whereKey($validated['base_snapshot_id'])->firstOrFail();
        $job = $this->imports->stage($this->user($request), $request->file('file'), $base);

        return response()->json(['data' => $this->detail($job)], 201);
    }

    public function show(ImportJob $import): JsonResponse
    {
        return response()->json(['data' => $this->detail($import)]);
    }

    /**
     * Apply a staged import, atomically. Reserved to another person than the one who staged it.
     */
    public function approve(Request $request, ImportJob $import): JsonResponse
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $this->imports->apply($this->user($request), $import, $validated['comment'] ?? null)->toSummary()]);
    }

    public function cancel(Request $request, ImportJob $import): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return response()->json(['data' => $this->imports->cancel($this->user($request), $import, $validated['reason'])->toSummary()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(ImportJob $job): array
    {
        return [
            ...$job->toSummary(),
            'rows' => $job->rows()->limit(1000)->get()->map(fn (ImportRow $row): array => [
                'line' => $row->line,
                'user_id' => $row->user_id,
                'status' => $row->status,
                'changes' => $row->changes,
                'errors' => $row->errors,
            ])->all(),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
