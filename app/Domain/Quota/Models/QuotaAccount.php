<?php

namespace App\Domain\Quota\Models;

use App\Domain\Subscription\Models\UserPack;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Projection of a pack's ledger. Never written outside QuotaLedger, in the same transaction as the entry.
 *
 * available = acquired - reserved - consumed - expired; all three buckets are non-negative (database check).
 *
 * @property string $user_pack_id
 * @property string $available
 * @property string $reserved
 * @property string $consumed
 * @property int $entries_count
 */
#[Fillable(['user_pack_id', 'available', 'reserved', 'consumed', 'entries_count'])]
class QuotaAccount extends Model
{
    protected $primaryKey = 'user_pack_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return BelongsTo<UserPack, $this>
     */
    public function userPack(): BelongsTo
    {
        return $this->belongsTo(UserPack::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entries_count' => 'integer',
        ];
    }
}
