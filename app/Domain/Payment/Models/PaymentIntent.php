<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\PaymentIntentStatus;
use App\Domain\Payment\Enums\PaymentPurpose;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An amount that must be paid for a given object (membership, later pack subscriptions).
 * The amount is a snapshot decided by the server and never changes.
 *
 * @property string $id
 * @property string $user_id
 * @property PaymentPurpose $purpose
 * @property string $payable_type
 * @property string $payable_id
 * @property int $amount net amount the CECT must receive
 * @property int $fee_amount provider fees added on top, borne by the customer
 * @property int $total_amount amount charged to the customer
 * @property string $currency
 * @property PaymentIntentStatus $status
 * @property Carbon|null $succeeded_at
 * @property Carbon|null $canceled_at
 * @property array<string, mixed>|null $metadata
 * @property-read User $user
 * @property-read Model $payable
 */
#[Fillable(['user_id', 'purpose', 'payable_type', 'payable_id', 'amount', 'fee_amount', 'total_amount', 'currency', 'status', 'succeeded_at', 'canceled_at', 'metadata'])]
class PaymentIntent extends Model
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
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<PaymentAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'total_amount' => 'integer',
            'status' => PaymentIntentStatus::class,
            'succeeded_at' => 'datetime',
            'canceled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
