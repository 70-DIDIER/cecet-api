<?php

namespace App\Domain\Shared\Outbox;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Work decided inside a business transaction and executed after it commits (e.g. sending a payout).
 *
 * @property string $id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon $available_at
 * @property Carbon|null $processed_at
 */
#[Fillable(['type', 'payload', 'status', 'attempts', 'last_error', 'available_at', 'processed_at'])]
class OutboxMessage extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_DEAD = 'dead';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
