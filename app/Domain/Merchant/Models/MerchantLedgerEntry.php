<?php

namespace App\Domain\Merchant\Models;

use App\Domain\Merchant\Enums\MerchantLedgerEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable movement of a merchant's balance. Corrections are compensating entries, never edits.
 *
 * @property string $id
 * @property string $user_pack_id
 * @property MerchantLedgerEntryType $type
 * @property string $eligible_delta
 * @property string $reserved_delta
 * @property string $redeemed_delta
 * @property string $eligible_after
 * @property string $reserved_after
 * @property string $redeemed_after
 * @property string $idempotency_key
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $reason
 * @property string|null $actor_id
 * @property Carbon $created_at
 */
#[Fillable([
    'user_pack_id', 'type', 'eligible_delta', 'reserved_delta', 'redeemed_delta', 'eligible_after', 'reserved_after', 'redeemed_after',
    'idempotency_key', 'source_type', 'source_id', 'reason', 'actor_id', 'correlation_id', 'created_at',
])]
class MerchantLedgerEntry extends Model
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MerchantLedgerEntryType::class,
            'created_at' => 'datetime',
        ];
    }
}
