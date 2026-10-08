<?php

namespace App\Domain\Payout\Models;

use App\Domain\Payout\Enums\PayoutItemStatus;
use App\Domain\Payout\Enums\PayoutRoute;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One technical transfer of a payout batch. A SUCCEEDED item is never sent again.
 *
 * `amount` is the share of the obligation it settles; `transfer_amount` is what was actually sent
 * (amount minus fees when the beneficiary bears them).
 *
 * @property string $id
 * @property string $payout_batch_id
 * @property int $sequence
 * @property PayoutRoute $kind
 * @property int $amount
 * @property int $fee_amount
 * @property int|null $transfer_amount
 * @property PayoutItemStatus $status
 * @property string|null $provider
 * @property string|null $provider_reference
 * @property string|null $merchant_reference
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $submitted_at
 * @property Carbon|null $settled_at
 * @property-read PayoutBatch $batch
 */
#[Fillable([
    'payout_batch_id', 'sequence', 'kind', 'amount', 'fee_amount', 'transfer_amount', 'status', 'provider',
    'provider_reference', 'merchant_reference', 'attempts', 'last_error', 'submitted_at', 'settled_at',
])]
class PayoutItem extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<PayoutBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'kind' => PayoutRoute::class,
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'transfer_amount' => 'integer',
            'status' => PayoutItemStatus::class,
            'attempts' => 'integer',
            'submitted_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
