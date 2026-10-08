<?php

namespace App\Domain\Subscription\Models;

use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Subscription\Enums\PackTopupStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A top-up of a Personnel pack: the amount paid and the YEM it buys, at the discount and rate of the day.
 *
 * @property string $id
 * @property string $user_id
 * @property string $user_pack_id
 * @property PackTopupStatus $status
 * @property int $amount_xof
 * @property int $discount_bps
 * @property string $rate_setting_key
 * @property int $rate_xof_per_yem
 * @property string $quantity
 * @property Carbon|null $credited_at
 * @property Carbon|null $canceled_at
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read UserPack $userPack
 */
#[Fillable(['user_id', 'user_pack_id', 'status', 'amount_xof', 'discount_bps', 'rate_setting_key', 'rate_xof_per_yem', 'quantity', 'credited_at', 'canceled_at'])]
class PackTopup extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<UserPack, $this>
     */
    public function userPack(): BelongsTo
    {
        return $this->belongsTo(UserPack::class);
    }

    /**
     * @return MorphMany<PaymentIntent, $this>
     */
    public function paymentIntents(): MorphMany
    {
        return $this->morphMany(PaymentIntent::class, 'payable');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PackTopupStatus::class,
            'amount_xof' => 'integer',
            'discount_bps' => 'integer',
            'rate_xof_per_yem' => 'integer',
            'credited_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }
}
