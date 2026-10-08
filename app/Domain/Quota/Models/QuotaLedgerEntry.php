<?php

namespace App\Domain\Quota\Models;

use App\Domain\Quota\Enums\LedgerEntryType;
use App\Domain\Subscription\Models\PackPeriod;
use App\Domain\Subscription\Models\UserPack;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable movement of quota. Corrections are made with compensating entries, never by editing.
 *
 * @property string $id
 * @property string $user_pack_id
 * @property LedgerEntryType $type
 * @property string $available_delta
 * @property string $reserved_delta
 * @property string $consumed_delta
 * @property string $available_after
 * @property string $reserved_after
 * @property string $consumed_after
 * @property string $idempotency_key
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $pack_period_id
 * @property string|null $reason
 * @property string|null $actor_id
 * @property string|null $correlation_id
 * @property Carbon $created_at
 * @property-read UserPack $userPack
 * @property-read PackPeriod|null $period
 */
#[Fillable([
    'user_pack_id', 'type', 'available_delta', 'reserved_delta', 'consumed_delta', 'available_after', 'reserved_after',
    'consumed_after', 'idempotency_key', 'source_type', 'source_id', 'pack_period_id', 'reason', 'actor_id', 'correlation_id', 'created_at',
])]
class QuotaLedgerEntry extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are append-only.'));
    }

    /**
     * @return BelongsTo<UserPack, $this>
     */
    public function userPack(): BelongsTo
    {
        return $this->belongsTo(UserPack::class);
    }

    /**
     * @return BelongsTo<PackPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(PackPeriod::class, 'pack_period_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'created_at' => 'datetime',
        ];
    }
}
