<?php

namespace App\Domain\Merchant\Models;

use App\Domain\Merchant\Enums\MerchantPaymentStatus;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment intent of a member to a merchant: the quantity reserved on the payer's pack, the merchant and its
 * Pernum as resolved by the server when the intent was created.
 *
 * @property string $id
 * @property string $reference
 * @property string $payer_id
 * @property string $payer_pack_id
 * @property string $merchant_id
 * @property string $merchant_pack_id
 * @property string|null $qr_code_id
 * @property MerchantPaymentStatus $status
 * @property string $quantity
 * @property string|null $payer_pernum
 * @property string $merchant_pernum
 * @property string|null $outlet_label
 * @property string $confirmation_mode
 * @property string|null $payer_transfer_reference
 * @property string|null $dispute_reason
 * @property Carbon $expires_at
 * @property Carbon|null $declared_at
 * @property Carbon|null $confirmed_at
 * @property string|null $confirmed_by
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property-read User $payer
 * @property-read UserPack $payerPack
 * @property-read User $merchant
 * @property-read UserPack $merchantPack
 */
#[Fillable([
    'reference', 'payer_id', 'payer_pack_id', 'merchant_id', 'merchant_pack_id', 'qr_code_id', 'status', 'quantity', 'payer_pernum',
    'merchant_pernum', 'outlet_label', 'confirmation_mode', 'payer_transfer_reference', 'dispute_reason', 'expires_at', 'declared_at',
    'confirmed_at', 'confirmed_by', 'closed_at',
])]
class MerchantPayment extends Model
{
    use HasUlids;

    public const MODE_MERCHANT = 'merchant';

    public const MODE_VALIDATOR = 'validator';

    /**
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    /**
     * @return BelongsTo<UserPack, $this>
     */
    public function payerPack(): BelongsTo
    {
        return $this->belongsTo(UserPack::class, 'payer_pack_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    /**
     * @return BelongsTo<UserPack, $this>
     */
    public function merchantPack(): BelongsTo
    {
        return $this->belongsTo(UserPack::class, 'merchant_pack_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MerchantPaymentStatus::class,
            'expires_at' => 'datetime',
            'declared_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
