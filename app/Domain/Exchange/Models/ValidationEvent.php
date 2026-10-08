<?php

namespace App\Domain\Exchange\Models;

use App\Domain\Exchange\Enums\ValidationAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable record of a Validator decision on a YEM receipt (who, when, what quantity, which external proof).
 *
 * @property string $id
 * @property string $exchange_request_id
 * @property ValidationAction $action
 * @property string|null $quantity_received
 * @property string|null $external_reference
 * @property string|null $comment
 * @property string $actor_id
 * @property Carbon $created_at
 */
#[Fillable(['exchange_request_id', 'action', 'quantity_received', 'external_reference', 'comment', 'actor_id', 'created_at'])]
class ValidationEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Validation events are append-only.'));
        static::deleting(fn () => throw new LogicException('Validation events are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ValidationAction::class,
            'created_at' => 'datetime',
        ];
    }
}
