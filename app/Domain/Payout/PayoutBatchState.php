<?php

namespace App\Domain\Payout;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payout\Enums\PayoutBatchStatus;
use App\Domain\Payout\Enums\PayoutItemStatus;
use App\Domain\Payout\Enums\PayoutRoute;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\Models\PayoutItem;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes what has been paid on a batch from its items (paid = sum of succeeded items) and derives its status.
 * When the obligation is fully paid, the operation that created it is notified.
 */
class PayoutBatchState
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function recompute(PayoutBatch $batch): PayoutBatch
    {
        return DB::transaction(function () use ($batch): PayoutBatch {
            $batch = PayoutBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $items = PayoutItem::query()->where('payout_batch_id', $batch->id)->get();

            $paid = (int) $items->where('status', PayoutItemStatus::Succeeded)->sum('amount');
            $remaining = $batch->total_amount - $paid;
            $wasPaid = $batch->status === PayoutBatchStatus::Paid;

            $status = match (true) {
                $remaining === 0 => PayoutBatchStatus::Paid,
                $batch->route === PayoutRoute::Manual && $items->where('kind', PayoutRoute::MobileMoney)->isEmpty() => PayoutBatchStatus::ManualRequired,
                $items->contains(fn (PayoutItem $item) => $item->status === PayoutItemStatus::Processing) => PayoutBatchStatus::Processing,
                $items->contains(fn (PayoutItem $item) => $item->status === PayoutItemStatus::Failed) => $paid > 0 ? PayoutBatchStatus::PartiallyPaid : PayoutBatchStatus::Failed,
                $paid > 0 => PayoutBatchStatus::PartiallyPaid,
                $batch->status === PayoutBatchStatus::Processing => PayoutBatchStatus::Processing,
                default => PayoutBatchStatus::AwaitingDispatch,
            };

            $batch->forceFill([
                'paid_amount' => $paid,
                'remaining_amount' => $remaining,
                'status' => $status,
                'paid_at' => $remaining === 0 ? ($batch->paid_at ?? now()) : null,
            ])->save();

            if ($status === PayoutBatchStatus::Paid && ! $wasPaid) {
                $this->auditLogger->record('payouts.paid', $batch, after: ['reference' => $batch->reference, 'paid_amount' => $paid]);

                $payable = $batch->payable;

                if ($payable instanceof PayoutPayable) {
                    $payable->onPayoutPaid($batch);
                }
            }

            return $batch;
        });
    }
}
