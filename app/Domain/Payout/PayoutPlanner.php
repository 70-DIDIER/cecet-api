<?php

namespace App\Domain\Payout;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payout\Enums\PayoutBatchStatus;
use App\Domain\Payout\Enums\PayoutItemStatus;
use App\Domain\Payout\Enums\PayoutRoute;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Outbox\Outbox;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Turns an amount owed into a payout batch, inside the transaction that created the debt.
 *
 * Routing (all thresholds are settings): Mobile Money transfers of at most the unit ceiling, up to the
 * configured number of transfers; beyond that, or when the beneficiary chose a bank transfer or cash,
 * the batch requires a controlled manual settlement. When automatic payouts are enabled, sending is handed
 * to the transactional outbox so that it happens only if the debt is committed.
 */
class PayoutPlanner
{
    public const OUTBOX_DISPATCH = 'payout.dispatch';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Outbox $outbox,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $destination  frozen payout destination of the beneficiary
     */
    public function plan(Model $payable, User $beneficiary, int $total, array $destination): PayoutBatch
    {
        $route = $this->route($total, (string) ($destination['channel'] ?? ''));
        $autoDispatch = $route === PayoutRoute::MobileMoney && $this->settings->boolean('payout.auto_enabled');

        $batch = PayoutBatch::query()->create([
            'reference' => 'PO-'.str_pad((string) DB::scalar("select nextval('payout_reference_seq')"), 7, '0', STR_PAD_LEFT),
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => (string) $payable->getKey(),
            'beneficiary_id' => $beneficiary->id,
            'total_amount' => $total,
            'paid_amount' => 0,
            'remaining_amount' => $total,
            'currency' => 'XOF',
            'route' => $route,
            'method' => (string) ($destination['method'] ?? ''),
            'destination' => $destination,
            'status' => match (true) {
                $route === PayoutRoute::Manual => PayoutBatchStatus::ManualRequired,
                $autoDispatch => PayoutBatchStatus::Processing,
                default => PayoutBatchStatus::AwaitingDispatch,
            },
        ]);

        if ($route === PayoutRoute::MobileMoney) {
            foreach ($this->split($total) as $index => $amount) {
                $batch->items()->create([
                    'sequence' => $index + 1,
                    'kind' => PayoutRoute::MobileMoney,
                    'amount' => $amount,
                    'status' => PayoutItemStatus::Pending,
                ]);
            }
        }

        if ($autoDispatch) {
            $this->outbox->push(self::OUTBOX_DISPATCH, ['payout_batch_id' => $batch->id]);
        }

        $this->auditLogger->record('payouts.created', $batch, after: [
            'reference' => $batch->reference,
            'total_amount' => $total,
            'route' => $route->value,
            'method' => $batch->method,
            'items' => $route === PayoutRoute::MobileMoney ? $this->split($total) : [],
            'auto_dispatch' => $autoDispatch,
        ], metadata: ['payable_type' => $batch->payable_type, 'payable_id' => $batch->payable_id]);

        return $batch;
    }

    private function route(int $total, string $channel): PayoutRoute
    {
        if ($channel !== PayoutRoute::MobileMoney->value) {
            return PayoutRoute::Manual;
        }

        $ceiling = $this->settings->integer('payout.mobile_money.unit_max_xof') * $this->settings->integer('payout.mobile_money.max_items');

        return $total <= $ceiling ? PayoutRoute::MobileMoney : PayoutRoute::Manual;
    }

    /**
     * Greedy split into transfers that never exceed the unit ceiling: 1 200 000 with a 500 000 ceiling
     * gives 500 000 + 500 000 + 200 000.
     *
     * @return list<int>
     */
    private function split(int $total): array
    {
        $unit = $this->settings->integer('payout.mobile_money.unit_max_xof');
        $amounts = [];

        for ($left = $total; $left > 0; $left -= min($unit, $left)) {
            $amounts[] = min($unit, $left);
        }

        return $amounts;
    }
}
