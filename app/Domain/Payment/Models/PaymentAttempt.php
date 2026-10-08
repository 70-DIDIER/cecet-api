<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One checkout opened at a provider for a payment intent.
 *
 * @property string $id
 * @property string $payment_intent_id
 * @property string $provider
 * @property string|null $method
 * @property string|null $declared_reference
 * @property Carbon|null $declared_at
 * @property string|null $validated_by
 * @property Carbon|null $validated_at
 * @property string|null $validation_comment
 * @property string|null $provider_transaction_id
 * @property string|null $provider_reference
 * @property PaymentAttemptStatus $status
 * @property string|null $checkout_url
 * @property int $amount amount charged to the customer, fees included
 * @property int $fee_amount
 * @property string $currency
 * @property bool $requires_review
 * @property string|null $review_reason
 * @property string|null $failure_reason
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $settled_at
 * @property Carbon $created_at
 * @property-read PaymentIntent $intent
 */
#[Fillable([
    'payment_intent_id', 'provider', 'method', 'declared_reference', 'declared_at', 'validated_by', 'validated_at', 'validation_comment', 'provider_transaction_id', 'provider_reference', 'status', 'checkout_url',
    'amount', 'fee_amount', 'currency', 'requires_review', 'review_reason', 'failure_reason', 'last_checked_at', 'settled_at',
])]
class PaymentAttempt extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<PaymentIntent, $this>
     */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'requires_review' => 'boolean',
            'declared_at' => 'datetime',
            'validated_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
