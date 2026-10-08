<?php

namespace App\Domain\Membership\Models;

use App\Domain\Membership\Enums\ActivationMode;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Payment\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Initial membership of a member, with the fee that applied when payment was started.
 *
 * @property string $id
 * @property string $user_id
 * @property MembershipStatus $status
 * @property int $fee_amount
 * @property string $fee_currency
 * @property string $fee_setting_key
 * @property Carbon|null $paid_at
 * @property Carbon|null $activated_at
 * @property ActivationMode|null $activation_mode
 * @property-read User $user
 */
#[Fillable(['user_id', 'status', 'fee_amount', 'fee_currency', 'fee_setting_key', 'paid_at', 'activated_at', 'activation_mode'])]
class Membership extends Model
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
            'status' => MembershipStatus::class,
            'fee_amount' => 'integer',
            'paid_at' => 'datetime',
            'activated_at' => 'datetime',
            'activation_mode' => ActivationMode::class,
        ];
    }
}
