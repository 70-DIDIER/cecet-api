<?php

namespace App\Domain\Reporting\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A staged import and its decision.
 *
 * @property string $id
 * @property string $dataset
 * @property string $status
 * @property string $base_snapshot_id
 * @property string $file_name
 * @property string $sha256
 * @property string $path
 * @property array{rows: int, unchanged: int, change: int, conflict: int, error: int} $summary
 * @property string $uploaded_by
 * @property string|null $decided_by
 * @property string|null $decision_reason
 * @property Carbon|null $decided_at
 * @property Carbon|null $applied_at
 * @property Carbon $created_at
 */
#[Fillable(['dataset', 'status', 'base_snapshot_id', 'file_name', 'sha256', 'path', 'summary', 'uploaded_by', 'decided_by', 'decision_reason', 'decided_at', 'applied_at'])]
class ImportJob extends Model
{
    use HasUlids;

    public const STAGED = 'staged';

    public const APPLIED = 'applied';

    public const CANCELED = 'canceled';

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->orderBy('line');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'dataset' => $this->dataset,
            'status' => $this->status,
            'base_snapshot_id' => $this->base_snapshot_id,
            'file_name' => $this->file_name,
            'sha256' => $this->sha256,
            'summary' => $this->summary,
            'can_be_applied' => $this->status === self::STAGED && $this->summary['error'] === 0 && $this->summary['conflict'] === 0 && $this->summary['change'] > 0,
            'uploaded_by' => $this->uploaded_by,
            'decided_by' => $this->decided_by,
            'decision_reason' => $this->decision_reason,
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'applied_at' => $this->applied_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }
}
