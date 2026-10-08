<?php

namespace App\Domain\Backoffice\Http\Controllers;

use App\Domain\Reporting\Imports\MemberImportService;
use App\Domain\Reporting\Models\ExportSnapshot;
use App\Domain\Reporting\Models\ImportJob;
use App\Domain\Shared\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Controlled re-import of the member file: stage it, review the differences, then have another person approve it.
 */
class ImportsController
{
    public function __construct(private readonly MemberImportService $imports) {}

    public function index(): View
    {
        return view('backoffice.imports.index', [
            'jobs' => ImportJob::query()->orderByDesc('created_at')->limit(50)->get(),
            'snapshots' => ExportSnapshot::query()->where('status', ExportSnapshot::READY)->orderByDesc('version')->limit(10)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'base_snapshot_id' => ['required', 'string', 'ulid'],
        ]);

        $base = ExportSnapshot::query()->whereKey($validated['base_snapshot_id'])->first();

        if ($base === null) {
            return back()->withErrors(['base_snapshot_id' => 'Le snapshot de référence est introuvable.']);
        }

        try {
            $job = $this->imports->stage($this->user($request), $request->file('file'), $base);
        } catch (ApiException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return redirect()->route('backoffice.imports.show', $job)->with('status', 'Fichier chargé : rien n\'est modifié tant qu\'il n\'est pas approuvé.');
    }

    public function show(ImportJob $import): View
    {
        return view('backoffice.imports.show', [
            'job' => $import,
            'rows' => $import->rows()->limit(1000)->get(),
            'uploader' => User::query()->find($import->uploaded_by),
        ]);
    }

    public function approve(Request $request, ImportJob $import): RedirectResponse
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        try {
            $this->imports->apply($this->user($request), $import, $validated['comment'] ?? null);
        } catch (ApiException $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return redirect()->route('backoffice.imports.show', $import)->with('status', 'Import appliqué : les modifications sont enregistrées et tracées.');
    }

    public function cancel(Request $request, ImportJob $import): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        try {
            $this->imports->cancel($this->user($request), $import, $validated['reason']);
        } catch (ApiException $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return redirect()->route('backoffice.imports')->with('status', 'Import annulé.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
