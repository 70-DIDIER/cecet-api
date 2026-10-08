<?php

namespace App\Domain\Payout\Models;

use App\Domain\Payout\Enums\ManualSettlementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bank transfer or cash payment recorded by Accounting. Above the configured threshold it must be approved
 * by another person (maker-checker) before it settles the batch.
 *
 * @property string $id
 * @property string $payout_batch_id
 * @property int $amount
 * @property string $method
 * @property string $reference
 * @property string|null $note
 * @property Carbon $paid_at
 * @property ManualSettlementStatus $status
 * @property string $recorded_by
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property-read PayoutBatch $batch
 */
#[Fillable(['payout_batch_id', 'amount', 'method', 'reference', 'note', 'paid_at', 'status', 'recorded_by', 'decided_by', 'decided_at', 'decision_reason'])]
class ManualSettlement extends Model
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
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'status' => ManualSettlementStatus::class,
            'decided_at' => 'datetime',
        ];
    }
}
