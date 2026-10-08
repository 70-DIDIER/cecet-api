<?php

namespace App\Domain\Payout\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Payout\Enums\ManualSettlementStatus;
use App\Domain\Payout\Enums\PayoutBatchStatus;
use App\Domain\Payout\Enums\PayoutItemStatus;
use App\Domain\Payout\Enums\PayoutRoute;
use App\Domain\Payout\Exceptions\PayoutException;
use App\Domain\Payout\Models\ManualSettlement;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\Models\PayoutItem;
use App\Domain\Payout\PayoutBatchState;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Bank transfers and cash payments recorded by Accounting.
 *
 * A settlement always covers the whole remaining amount, never while a transfer is in progress (that would risk
 * paying twice). At or above the configured threshold it waits for the approval of another person (maker-checker);
 * once applied it cancels the transfers that were not sent and settles the batch.
 */
class ManualSettlementService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly PayoutBatchState $state,
        private readonly AuditLogger $auditLogger,
        private readonly SecurityEventLogger $securityLogger,
    ) {}

    public function record(User $maker, PayoutBatch $batch, string $method, string $reference, int $amount, CarbonInterface $paidAt, ?string $note): ManualSettlement
    {
        return DB::transaction(function () use ($maker, $batch, $method, $reference, $amount, $paidAt, $note): ManualSettlement {
            $batch = PayoutBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->assertSettleable($batch, $amount);

            if (ManualSettlement::query()->where('payout_batch_id', $batch->id)->where('status', ManualSettlementStatus::PendingApproval)->exists()) {
                throw PayoutException::settlementPending();
            }

            $needsApproval = $amount >= $this->settings->integer('finance.maker_checker_threshold_xof');

            $settlement = ManualSettlement::query()->create([
                'payout_batch_id' => $batch->id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'note' => $note,
                'paid_at' => $paidAt,
                'status' => $needsApproval ? ManualSettlementStatus::PendingApproval : ManualSettlementStatus::Applied,
                'recorded_by' => $maker->id,
            ]);

            $this->auditLogger->record('payouts.manual_settlement_recorded', $batch, after: [
                'settlement_id' => $settlement->id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'requires_approval' => $needsApproval,
            ], actor: $maker);

            if (! $needsApproval) {
                $this->apply($batch, $settlement);
            }

            return $settlement;
        });
    }

    public function approve(User $checker, ManualSettlement $settlement): ManualSettlement
    {
        $this->assertNotMaker($checker, $settlement);

        return DB::transaction(function () use ($checker, $settlement): ManualSettlement {
            $settlement = $this->pendingDecision($checker, $settlement);
            $batch = PayoutBatch::query()->lockForUpdate()->findOrFail($settlement->payout_batch_id);
            $this->assertSettleable($batch, $settlement->amount);

            $settlement->forceFill(['status' => ManualSettlementStatus::Applied, 'decided_by' => $checker->id, 'decided_at' => now()])->save();
            $this->auditLogger->record('payouts.manual_settlement_approved', $batch, after: ['settlement_id' => $settlement->id, 'amount' => $settlement->amount], actor: $checker);

            $this->apply($batch, $settlement);

            return $settlement;
        });
    }

    public function reject(User $checker, ManualSettlement $settlement, string $reason): ManualSettlement
    {
        $this->assertNotMaker($checker, $settlement);

        return DB::transaction(function () use ($checker, $settlement, $reason): ManualSettlement {
            $settlement = $this->pendingDecision($checker, $settlement);

            $settlement->forceFill([
                'status' => ManualSettlementStatus::Rejected,
                'decided_by' => $checker->id,
                'decided_at' => now(),
                'decision_reason' => $reason,
            ])->save();

            $this->auditLogger->record('payouts.manual_settlement_rejected', $settlement->batch, after: ['settlement_id' => $settlement->id], reason: $reason, actor: $checker);

            return $settlement;
        });
    }

    private function pendingDecision(User $checker, ManualSettlement $settlement): ManualSettlement
    {
        $settlement = ManualSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

        if ($settlement->status !== ManualSettlementStatus::PendingApproval) {
            throw PayoutException::settlementNotPending();
        }

        if ($settlement->recorded_by === $checker->id) {
            throw PayoutException::sameApprover();
        }

        return $settlement;
    }

    /**
     * Checked before any transaction so that the attempt is logged even though the operation is refused.
     */
    private function assertNotMaker(User $checker, ManualSettlement $settlement): void
    {
        if (ManualSettlement::query()->whereKey($settlement->id)->value('recorded_by') === $checker->id) {
            $this->securityLogger->record('finance.maker_checker_violation', SecuritySeverity::Warning, $checker->id, metadata: ['settlement_id' => $settlement->id]);

            throw PayoutException::sameApprover();
        }
    }

    private function assertSettleable(PayoutBatch $batch, int $amount): void
    {
        if ($batch->status === PayoutBatchStatus::Paid || $batch->remaining_amount === 0) {
            throw PayoutException::alreadyPaid();
        }

        if (PayoutItem::query()->where('payout_batch_id', $batch->id)->where('status', PayoutItemStatus::Processing)->exists()) {
            throw PayoutException::transferInProgress();
        }

        if ($amount !== $batch->remaining_amount) {
            throw PayoutException::amountMustMatchRemaining($batch->remaining_amount);
        }
    }

    private function apply(PayoutBatch $batch, ManualSettlement $settlement): void
    {
        PayoutItem::query()
            ->where('payout_batch_id', $batch->id)
            ->whereIn('status', [PayoutItemStatus::Pending, PayoutItemStatus::Failed])
            ->update(['status' => PayoutItemStatus::Canceled->value]);

        $batch->items()->create([
            'sequence' => (int) PayoutItem::query()->where('payout_batch_id', $batch->id)->max('sequence') + 1,
            'kind' => PayoutRoute::Manual,
            'amount' => $settlement->amount,
            'fee_amount' => 0,
            'transfer_amount' => $settlement->amount,
            'status' => PayoutItemStatus::Succeeded,
            'provider' => 'manual',
            'provider_reference' => $settlement->reference,
            'submitted_at' => $settlement->paid_at,
            'settled_at' => now(),
        ]);

        $this->state->recompute($batch);
    }
}
