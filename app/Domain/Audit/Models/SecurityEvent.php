<?php

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\SecuritySeverity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable security journal entry (authentication, OTP, privileges, webhooks).
 *
 * @property string $id
 * @property Carbon $occurred_at
 * @property string $event_type
 * @property SecuritySeverity $severity
 * @property string|null $user_id
 * @property string|null $identifier
 * @property array<string, mixed>|null $metadata
 * @property string|null $correlation_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
#[Fillable(['occurred_at', 'event_type', 'severity', 'user_id', 'identifier', 'metadata', 'correlation_id', 'ip_address', 'user_agent'])]
class SecurityEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    /**
     * Microsecond precision for timestamptz(6) columns.
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Security events are append-only.'));
        static::deleting(fn () => throw new LogicException('Security events are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'severity' => SecuritySeverity::class,
            'metadata' => 'array',
        ];
    }
}
