<?php

namespace App\Domain\Reporting\Imports;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Http\Requests\RegisterMemberRequest;
use App\Domain\Reporting\Models\ExportSnapshot;
use App\Domain\Reporting\Models\ImportJob;
use App\Domain\Reporting\Models\ImportRow;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Re-import of the member file of a snapshot, edited outside the platform (cahier v4.0 §10.2, AC-20).
 *
 * Only the fields the Superadmin allows (imports.member_fields: phone, city) can change; identities, Pernums,
 * emails, memberships and anything financial never do. Each value is compared three ways:
 * - imported = snapshot value: untouched in the file, ignored;
 * - current = snapshot value: a change to apply;
 * - current = imported value: already done, ignored;
 * - otherwise the data also changed on the platform since the snapshot: a conflict, never overwritten.
 *
 * Staging writes nothing to the members. Applying requires a second authorised person, re-checks every value under
 * lock and runs in one transaction: either every change is applied, or none.
 */
class MemberImportService
{
    public const DATASET = 'members';

    private const SOURCE_FILE = 'membres.csv';

    private const MAX_ROWS = 50000;

    private const IDENTITY_COLUMNS = ['user_id', 'member_number'];

    /**
     * CSV column => users column, for the fields that may ever be imported.
     */
    private const IMPORTABLE = ['phone' => 'phone', 'city' => 'city'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function stage(User $uploader, UploadedFile $file, ExportSnapshot $base): ImportJob
    {
        if ($base->status !== ExportSnapshot::READY || $base->path === null || ! Storage::disk('local')->exists($base->path)) {
            throw ImportException::baseSnapshotUnavailable();
        }

        $baseRows = $this->readBaseRows($base);
        $header = array_keys(reset($baseRows) ?: []);
        $content = (string) file_get_contents($file->getRealPath());
        [$fileHeader, $lines] = $this->parse($content);

        if ($header === [] || $fileHeader !== $header) {
            throw ImportException::schemaMismatch($header);
        }

        if (count($lines) > self::MAX_ROWS) {
            throw ImportException::tooManyRows(self::MAX_ROWS);
        }

        $sha256 = hash('sha256', $content);

        if (ImportJob::query()->where('sha256', $sha256)->where('status', ImportJob::APPLIED)->exists()) {
            throw ImportException::alreadyApplied();
        }

        $allowed = array_values(array_intersect(array_map('strval', $this->settings->array('imports.member_fields')), array_keys(self::IMPORTABLE)));
        $current = User::query()->where('kind', UserKind::Member)->whereIn('id', array_keys($baseRows))->get()->keyBy('id');

        $rows = [];
        $seen = [];

        foreach ($lines as $index => $values) {
            $line = $index + 2;
            $record = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
            $userId = $record['user_id'];
            $errors = [];

            if (count($values) !== count($header)) {
                $errors[] = 'Nombre de colonnes incorrect.';
            }

            $baseRow = $baseRows[$userId] ?? null;
            $user = $current->get($userId);

            if ($baseRow === null || $user === null) {
                $rows[] = ['line' => $line, 'user_id' => null, 'status' => 'error', 'changes' => null, 'errors' => [...$errors, 'Membre inconnu du snapshot de référence ou de la plateforme.']];

                continue;
            }

            if (isset($seen[$userId])) {
                $errors[] = "Membre déjà présent ligne {$seen[$userId]}.";
            }

            $seen[$userId] = $line;

            if ($record['member_number'] !== $baseRow['member_number'] || $record['member_number'] !== (string) $user->member_number) {
                $errors[] = 'Le numéro de membre ne correspond pas à l\'identifiant.';
            }

            $changes = [];

            foreach ($header as $column) {
                if (in_array($column, self::IDENTITY_COLUMNS, true) || $record[$column] === $baseRow[$column]) {
                    continue;
                }

                if (! in_array($column, $allowed, true)) {
                    $errors[] = "La colonne « {$column} » ne peut pas être modifiée par import.";

                    continue;
                }

                $error = $this->validate($column, $record[$column]);

                if ($error !== null) {
                    $errors[] = $error;

                    continue;
                }

                $changes[$column] = [
                    'base' => $this->nullable($baseRow[$column]),
                    'imported' => $this->nullable($record[$column]),
                    'current' => $user->getAttribute(self::IMPORTABLE[$column]),
                ];
            }

            $rows[] = ['line' => $line, 'user_id' => $userId, ...$this->classify($changes, $errors)];
        }

        return DB::transaction(function () use ($uploader, $file, $base, $sha256, $content, $rows): ImportJob {
            $summary = ['rows' => count($rows), 'unchanged' => 0, 'change' => 0, 'conflict' => 0, 'error' => 0];

            foreach ($rows as $row) {
                $summary[$row['status']]++;
            }

            $job = ImportJob::query()->create([
                'dataset' => self::DATASET,
                'status' => ImportJob::STAGED,
                'base_snapshot_id' => $base->id,
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'sha256' => $sha256,
                'path' => '',
                'summary' => $summary,
                'uploaded_by' => $uploader->id,
            ]);

            $path = "imports/{$job->id}.csv";
            Storage::disk('local')->put($path, $content);
            $job->forceFill(['path' => $path])->save();

            foreach ($rows as $row) {
                if ($row['status'] !== 'unchanged') {
                    ImportRow::query()->create([...$row, 'import_job_id' => $job->id]);
                }
            }

            $this->auditLogger->record('import_jobs.staged', $job, after: ['sha256' => $sha256, 'base_snapshot_version' => $base->version, ...$summary], actor: $uploader);

            return $job;
        });
    }

    /**
     * Applies a staged import. The approver must be another person than the uploader; every value is checked again
     * under lock, and any change on the platform since staging aborts the whole import.
     */
    public function apply(User $approver, ImportJob $job, ?string $comment): ImportJob
    {
        return DB::transaction(function () use ($approver, $job, $comment): ImportJob {
            $job = ImportJob::query()->lockForUpdate()->findOrFail($job->id);

            if ($job->status !== ImportJob::STAGED) {
                throw ImportException::notStaged($job->status);
            }

            if ($job->uploaded_by === $approver->id) {
                throw ImportException::sameApprover();
            }

            if ($job->summary['error'] > 0 || $job->summary['conflict'] > 0 || $job->summary['change'] === 0) {
                throw ImportException::notApplicable($job->summary);
            }

            $applied = 0;

            foreach ($job->rows()->where('status', 'change')->get() as $row) {
                $user = User::query()->lockForUpdate()->findOrFail($row->user_id);
                $before = [];
                $after = [];

                foreach ($row->changes ?? [] as $column => $change) {
                    $attribute = self::IMPORTABLE[$column];

                    if ($user->getAttribute($attribute) !== $change['current']) {
                        throw ImportException::staleData($row->line, $column);
                    }

                    $before[$attribute] = $change['current'];
                    $after[$attribute] = $change['imported'];
                }

                $user->forceFill($after)->save();
                $applied++;

                $this->auditLogger->record('imports.member_updated', $user, before: $before, after: $after, metadata: ['import_job_id' => $job->id, 'line' => $row->line], actor: $approver);
            }

            $job->forceFill(['status' => ImportJob::APPLIED, 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_reason' => $comment, 'applied_at' => now()])->save();
            $this->auditLogger->record('imports.applied', $job, after: ['members_updated' => $applied, 'sha256' => $job->sha256], reason: $comment, actor: $approver);

            return $job;
        });
    }

    public function cancel(User $actor, ImportJob $job, string $reason): ImportJob
    {
        return DB::transaction(function () use ($actor, $job, $reason): ImportJob {
            $job = ImportJob::query()->lockForUpdate()->findOrFail($job->id);

            if ($job->status !== ImportJob::STAGED) {
                throw ImportException::notStaged($job->status);
            }

            $job->forceFill(['status' => ImportJob::CANCELED, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => $reason])->save();
            $this->auditLogger->record('import_jobs.canceled', $job, reason: $reason, actor: $actor);

            return $job;
        });
    }

    /**
     * @param  array<string, array{base: string|null, imported: string|null, current: mixed}>  $changes
     * @param  list<string>  $errors
     * @return array{status: string, changes: array<string, mixed>|null, errors: list<string>|null}
     */
    private function classify(array $changes, array $errors): array
    {
        if ($errors !== []) {
            return ['status' => 'error', 'changes' => $changes === [] ? null : $changes, 'errors' => $errors];
        }

        $toApply = [];
        $conflict = false;

        foreach ($changes as $column => $change) {
            if ($change['current'] === $change['imported']) {
                continue;
            }

            if ($change['current'] !== $change['base']) {
                $conflict = true;
            }

            $toApply[$column] = $change;
        }

        if ($toApply === []) {
            return ['status' => 'unchanged', 'changes' => null, 'errors' => null];
        }

        return ['status' => $conflict ? 'conflict' : 'change', 'changes' => $toApply, 'errors' => null];
    }

    private function validate(string $column, string $value): ?string
    {
        return match ($column) {
            'phone' => preg_match(RegisterMemberRequest::PHONE_FORMAT, $value) === 1 ? null : "Téléphone invalide : « {$value} » (format international attendu, ex. +22890123456).",
            'city' => mb_strlen($value) <= 100 ? null : 'La ville dépasse 100 caractères.',
            default => "La colonne « {$column} » ne peut pas être modifiée par import.",
        };
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * The member file of the base snapshot, read from its archive after checking the archive's hash.
     *
     * @return array<string, array<string, string>> user_id => row
     */
    private function readBaseRows(ExportSnapshot $base): array
    {
        $archive = (string) Storage::disk('local')->get((string) $base->path);

        if (! hash_equals((string) $base->sha256, hash('sha256', $archive))) {
            throw ImportException::baseSnapshotUnavailable();
        }

        $temporary = (string) tempnam(sys_get_temp_dir(), 'cect-import');
        file_put_contents($temporary, $archive);

        try {
            $zip = new ZipArchive;

            if ($zip->open($temporary) !== true) {
                throw ImportException::baseSnapshotUnavailable();
            }

            $content = $zip->getFromName(self::SOURCE_FILE);
            $zip->close();
        } finally {
            @unlink($temporary);
        }

        if ($content === false) {
            throw ImportException::baseSnapshotUnavailable();
        }

        [$header, $lines] = $this->parse($content);
        $rows = [];

        foreach ($lines as $values) {
            $record = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
            $rows[$record['user_id']] = $record;
        }

        return $rows;
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function parse(string $content): array
    {
        $content = str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ImportException::invalidEncoding();
        }

        $stream = fopen('php://temp', 'r+b') ?: throw ImportException::invalidEncoding();
        fwrite($stream, $content);
        rewind($stream);

        $header = null;
        $lines = [];

        while (($values = fgetcsv($stream, null, ';', '"', '')) !== false) {
            if ($values === [null]) {
                continue;
            }

            $values = array_map(fn (?string $value): string => $this->unescape((string) $value), $values);

            if ($header === null) {
                $header = $values;

                continue;
            }

            $lines[] = $values;
        }

        fclose($stream);

        return [$header ?? [], $lines];
    }

    /**
     * Undoes the formula neutralisation of CsvWriter ('=... becomes =...), so that a value read back is the value.
     */
    private function unescape(string $value): string
    {
        return strlen($value) > 1 && $value[0] === "'" && in_array($value[1], ['=', '+', '-', '@', "\t", "\r"], true) ? substr($value, 1) : $value;
    }
}
