<?php

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\AuditResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable business audit entry. Position and hash chain are assigned by the database.
 *
 * @property string $id
 * @property int $position
 * @property Carbon $occurred_at
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property AuditResult $result
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property array<string, mixed>|null $metadata
 * @property string|null $reason
 * @property string|null $correlation_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $prev_hash
 * @property string $hash
 */
#[Fillable([
    'occurred_at', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'result',
    'before', 'after', 'metadata', 'reason', 'correlation_id', 'ip_address', 'user_agent',
])]
class AuditEvent extends Model
{
    use HasUlids;

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public $timestamps = false;

    /**
     * Microsecond precision for timestamptz(6) columns.
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit events are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'result' => AuditResult::class,
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
        ];
    }
}
