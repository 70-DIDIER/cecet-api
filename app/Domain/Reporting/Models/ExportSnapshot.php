<?php

namespace App\Domain\Reporting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A consolidated snapshot archive (ZIP of CSV files + manifest).
 *
 * @property string $id
 * @property int $version
 * @property string $status
 * @property string $trigger
 * @property string|null $requested_by
 * @property int $schema_version
 * @property int|null $audit_position
 * @property string|null $path
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property array<string, mixed>|null $manifest
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $generated_at
 * @property Carbon|null $pruned_at
 * @property Carbon $created_at
 */
#[Fillable(['version', 'status', 'trigger', 'requested_by', 'schema_version', 'audit_position', 'path', 'size_bytes', 'sha256', 'manifest', 'error', 'started_at', 'generated_at', 'pruned_at'])]
class ExportSnapshot extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const PRUNED = 'pruned';

    /**
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'schema_version' => $this->schema_version,
            'audit_position' => $this->audit_position,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'files' => $this->manifest['files'] ?? null,
            'error' => $this->error,
            'generated_at' => $this->generated_at?->toIso8601ZuluString(),
            'pruned_at' => $this->pruned_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema_version' => 'integer',
            'audit_position' => 'integer',
            'size_bytes' => 'integer',
            'manifest' => 'array',
            'started_at' => 'datetime',
            'generated_at' => 'datetime',
            'pruned_at' => 'datetime',
        ];
    }
}
