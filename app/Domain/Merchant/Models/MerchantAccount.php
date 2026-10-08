<?php

namespace App\Domain\Merchant\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Projection of a merchant pack's ledger: collections eligible for redemption, reserved for a redemption, redeemed.
 *
 * @property string $user_pack_id
 * @property string $eligible
 * @property string $reserved
 * @property string $redeemed
 * @property int $entries_count
 */
#[Fillable(['user_pack_id', 'eligible', 'reserved', 'redeemed', 'entries_count'])]
class MerchantAccount extends Model
{
    protected $primaryKey = 'user_pack_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['entries_count' => 'integer'];
    }
}
