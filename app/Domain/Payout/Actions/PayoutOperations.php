<?php

namespace App\Domain\Payout\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payout\Enums\PayoutBatchStatus;
use App\Domain\Payout\Enums\PayoutItemStatus;
use App\Domain\Payout\Enums\PayoutRoute;
use App\Domain\Payout\Exceptions\PayoutException;
use App\Domain\Payout\Exceptions\PayoutProviderException;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\Models\PayoutItem;
use App\Domain\Payout\PayoutBatchState;
use App\Domain\Payout\PayoutProviderManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Accounting operations on Mobile Money payouts: send when automatic payouts are off, retry failed transfers only,
 * and reconcile transfers whose outcome is not known yet.
 */
class PayoutOperations
{
    /**
     * A transfer created at the provider but not started is started again after this delay.
     */
    private const START_RETRY_SECONDS = 120;

    /**
     * A transfer the provider never heard of (lost before creation) is declared failed after this delay.
     */
    private const NOT_FOUND_FAILURE_MINUTES = 30;

    public function __construct(
        private readonly SendPayoutBatch $sender,
        private readonly PayoutBatchState $state,
        private readonly PayoutProviderManager $providers,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function dispatch(User $actor, PayoutBatch $batch): PayoutBatch
    {
        DB::transaction(function () use ($actor, $batch): void {
            $locked = PayoutBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($locked->route !== PayoutRoute::MobileMoney || $locked->status !== PayoutBatchStatus::AwaitingDispatch) {
                throw PayoutException::notDispatchable($locked->status->value);
            }

            $locked->forceFill(['status' => PayoutBatchStatus::Processing])->save();
            $this->auditLogger->record('payouts.dispatched', $locked, after: ['reference' => $locked->reference], actor: $actor);
        });

        return $this->sender->send($batch->refresh());
    }

    /**
     * Puts failed transfers back in line and sends them again. Succeeded transfers are never touched.
     */
    public function retry(User $actor, PayoutBatch $batch, string $reason): PayoutBatch
    {
        DB::transaction(function () use ($actor, $batch, $reason): void {
            $locked = PayoutBatch::query()->lockForUpdate()->findOrFail($batch->id);

            $failed = PayoutItem::query()
                ->where('payout_batch_id', $locked->id)
                ->where('status', PayoutItemStatus::Failed)
                ->lockForUpdate()
                ->get();

            if ($failed->isEmpty()) {
                throw PayoutException::nothingToRetry();
            }

            PayoutItem::query()->whereKey($failed->modelKeys())->update(['status' => PayoutItemStatus::Pending->value, 'settled_at' => null]);
            $locked->forceFill(['status' => PayoutBatchStatus::Processing])->save();

            $this->auditLogger->record('payouts.retried', $locked, after: [
                'items' => $failed->pluck('sequence')->all(),
                'amount' => (int) $failed->sum('amount'),
            ], reason: $reason, actor: $actor);
        });

        return $this->sender->send($batch->refresh());
    }

    /**
     * Reconciles transfers in PROCESSING with the provider (by provider reference, or by our merchant reference when
     * the response was lost).
     *
     * @return int number of items whose status changed
     */
    public function refresh(?PayoutBatch $only = null): int
    {
        $changed = 0;
        $batches = [];

        PayoutItem::query()
            ->where('status', PayoutItemStatus::Processing)
            ->when($only, fn ($query, PayoutBatch $batch) => $query->where('payout_batch_id', $batch->id))
            ->orderBy('submitted_at')
            ->each(function (PayoutItem $item) use (&$changed, &$batches): void {
                if ($this->reconcile($item)) {
                    $changed++;
                }

                $batches[$item->payout_batch_id] = true;
            });

        foreach (array_keys($batches) as $batchId) {
            $this->state->recompute(PayoutBatch::query()->findOrFail($batchId));
        }

        return $changed;
    }

    private function reconcile(PayoutItem $item): bool
    {
        if ($item->provider === null || $item->merchant_reference === null) {
            return false;
        }

        try {
            $provider = $this->providers->provider($item->provider);
            $result = $item->provider_reference !== null
                ? $provider->fetch($item->provider_reference)
                : $provider->findByMerchantReference($item->merchant_reference);

            if ($result === null) {
                if ($item->submitted_at !== null && $item->submitted_at->lessThan(now()->subMinutes(self::NOT_FOUND_FAILURE_MINUTES))) {
                    $item->forceFill(['status' => PayoutItemStatus::Failed, 'last_error' => 'not_found_at_provider', 'settled_at' => now()])->save();

                    return true;
                }

                return false;
            }

            if ($result->needsStart && $item->submitted_at !== null && $item->submitted_at->lessThan(now()->subSeconds(self::START_RETRY_SECONDS))) {
                $result = $provider->start($result->providerReference);
            }
        } catch (PayoutProviderException $exception) {
            report($exception);

            return false;
        }

        $before = $item->status;
        $this->sender->apply($item, $result);

        return $item->status !== $before;
    }
}
