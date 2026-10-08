<?php

namespace App\Domain\Reporting\Snapshots;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\AuditResult;
use App\Domain\Reporting\CsvWriter;
use App\Domain\Reporting\Models\ExportSnapshot;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Generates consolidated snapshots (cahier v4.0 §10.1, AC-19).
 *
 * - Every dataset is read in one REPEATABLE READ, READ ONLY transaction: all files describe the same instant.
 * - The archive holds one CSV per dataset and a manifest (snapshot id, version, schema version, generation date,
 *   audit position covered, rows and SHA-256 of each file); the archive's own SHA-256 is recorded in the database.
 * - A PostgreSQL advisory lock guarantees that two generations never run at the same time.
 * - Automatic generation runs only when business events happened since the last snapshot, at most once per
 *   configured interval. Old archives are deleted beyond the retention count; their metadata and hash are kept.
 */
class SnapshotGenerator
{
    private const LOCK_KEY = 74_201_001;

    private const DIRECTORY = 'snapshots';

    public function __construct(
        private readonly SnapshotDatasets $datasets,
        private readonly SettingsService $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Business events recorded since the last snapshot, and the minimum interval elapsed.
     */
    public function isDue(): bool
    {
        if (! $this->settings->boolean('reporting.snapshots_enabled')) {
            return false;
        }

        $last = ExportSnapshot::query()->whereIn('status', [ExportSnapshot::READY, ExportSnapshot::PRUNED])->orderByDesc('version')->first();

        if ($last?->generated_at !== null && $last->generated_at->greaterThan(now()->subMinutes($this->settings->integer('reporting.snapshot_min_interval_minutes')))) {
            return false;
        }

        $prefixes = array_map('strval', $this->settings->array('reporting.snapshot_trigger_prefixes'));

        if ($prefixes === []) {
            return false;
        }

        return DB::table('audit_events')
            ->where('position', '>', $last->audit_position ?? 0)
            ->where(function ($query) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $query->orWhere('action', 'like', addcslashes($prefix, '%_\\').'%');
                }
            })
            ->exists();
    }

    /**
     * Generates a snapshot now. Returns null when another generation is already running.
     */
    public function generate(string $trigger = 'automatic', ?User $requestedBy = null): ?ExportSnapshot
    {
        if (! (bool) DB::scalar('select pg_try_advisory_lock(?)', [self::LOCK_KEY])) {
            return null;
        }

        try {
            $snapshot = ExportSnapshot::query()->create([
                'version' => (int) DB::scalar("select nextval('export_snapshot_version_seq')"),
                'status' => ExportSnapshot::PENDING,
                'trigger' => $trigger,
                'requested_by' => $requestedBy?->id,
                'schema_version' => SnapshotDatasets::SCHEMA_VERSION,
                'started_at' => now(),
            ]);

            try {
                $this->build($snapshot);
            } catch (Throwable $exception) {
                $snapshot->forceFill(['status' => ExportSnapshot::FAILED, 'error' => mb_substr($exception->getMessage(), 0, 2000)])->save();
                $this->auditLogger->record('snapshots.failed', $snapshot, result: AuditResult::Failure, metadata: ['version' => $snapshot->version, 'error' => mb_substr($exception->getMessage(), 0, 500)], actor: $requestedBy);

                return $snapshot;
            }

            $this->auditLogger->record('snapshots.generated', $snapshot, after: [
                'version' => $snapshot->version,
                'sha256' => $snapshot->sha256,
                'audit_position' => $snapshot->audit_position,
            ], actor: $requestedBy);

            $this->prune();

            return $snapshot;
        } finally {
            DB::scalar('select pg_advisory_unlock(?)', [self::LOCK_KEY]);
        }
    }

    private function build(ExportSnapshot $snapshot): void
    {
        $workDirectory = storage_path('app/tmp/snapshot-'.$snapshot->id);

        if (! is_dir($workDirectory) && ! mkdir($workDirectory, 0700, true)) {
            throw new RuntimeException('Impossible de créer le répertoire de travail du snapshot.');
        }

        try {
            [$files, $auditPosition, $generatedAt] = DB::transaction(function () use ($workDirectory): array {
                // Only possible as the first statement of the outermost transaction (not inside a test's transaction).
                if (DB::transactionLevel() === 1) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }

                $auditPosition = DB::table('audit_events')->max('position');
                $generatedAt = now();
                $files = [];

                foreach ($this->datasets->all() as $name => $rows) {
                    $path = $workDirectory.'/'.$name;
                    $stream = fopen($path, 'wb') ?: throw new RuntimeException("Impossible d'écrire {$name}.");
                    $count = CsvWriter::write($stream, $rows);
                    fclose($stream);

                    $files[$name] = ['rows' => $count, 'sha256' => hash_file('sha256', $path)];
                }

                return [$files, $auditPosition === null ? null : (int) $auditPosition, $generatedAt];
            });

            $manifest = [
                'snapshot_id' => $snapshot->id,
                'version' => $snapshot->version,
                'schema_version' => SnapshotDatasets::SCHEMA_VERSION,
                'generated_at' => $generatedAt->toIso8601ZuluString(),
                'generated_by' => $snapshot->requested_by ?? 'system',
                'trigger' => $snapshot->trigger,
                'audit_position' => $auditPosition,
                'scope' => 'Copie de consultation hors plateforme. La plateforme reste la seule source de vérité.',
                'files' => $files,
            ];

            $zipPath = $workDirectory.'.zip';
            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Impossible de créer l\'archive du snapshot.');
            }

            $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            foreach (array_keys($files) as $name) {
                $zip->addFile($workDirectory.'/'.$name, $name);
            }

            $zip->close();

            $storedPath = sprintf('%s/cect-snapshot-v%06d-%s.zip', self::DIRECTORY, $snapshot->version, $snapshot->id);
            $stream = fopen($zipPath, 'rb') ?: throw new RuntimeException('Archive du snapshot illisible.');
            Storage::disk('local')->writeStream($storedPath, $stream);
            fclose($stream);

            $snapshot->forceFill([
                'status' => ExportSnapshot::READY,
                'audit_position' => $auditPosition,
                'path' => $storedPath,
                'size_bytes' => (int) filesize($zipPath),
                'sha256' => hash_file('sha256', $zipPath),
                'manifest' => $manifest,
                'generated_at' => $generatedAt,
            ])->save();

            @unlink($zipPath);
        } finally {
            foreach (glob($workDirectory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($workDirectory);
        }
    }

    /**
     * Deletes the archives beyond the retention count; their metadata and hash stay in the history.
     */
    private function prune(): void
    {
        $keep = max(1, $this->settings->integer('reporting.snapshot_retention_count'));

        ExportSnapshot::query()
            ->where('status', ExportSnapshot::READY)
            ->orderByDesc('version')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->get()
            ->each(function (ExportSnapshot $old): void {
                if ($old->path !== null) {
                    Storage::disk('local')->delete($old->path);
                }

                $old->forceFill(['status' => ExportSnapshot::PRUNED, 'pruned_at' => now()])->save();
            });
    }
}
