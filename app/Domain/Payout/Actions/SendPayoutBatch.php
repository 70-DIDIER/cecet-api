<?php

namespace App\Domain\Payout\Actions;

use App\Domain\Payout\Contracts\PayoutProvider;
use App\Domain\Payout\Data\PayoutRequest;
use App\Domain\Payout\Data\PayoutResult;
use App\Domain\Payout\Enums\PayoutBatchStatus;
use App\Domain\Payout\Enums\PayoutItemStatus;
use App\Domain\Payout\Enums\PayoutRoute;
use App\Domain\Payout\Enums\ProviderPayoutStatus;
use App\Domain\Payout\Exceptions\PayoutException;
use App\Domain\Payout\Exceptions\PayoutProviderException;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\Models\PayoutItem;
use App\Domain\Payout\PayoutBatchState;
use App\Domain\Payout\PayoutFeeCalculator;
use App\Domain\Payout\PayoutProviderManager;
use App\Domain\Shared\Outbox\OutboxHandler;
use Illuminate\Support\Facades\DB;

/**
 * Sends the pending items of a payout batch.
 *
 * Each item is first claimed in a committed transaction (PROCESSING, attempt number, unique merchant reference),
 * then sent outside any transaction. An item whose outcome is unknown (timeout, lost response) stays PROCESSING
 * and is reconciled by the refresh job through its merchant reference: it is never sent a second time blindly.
 * Only PENDING items are ever sent, so a SUCCEEDED transfer can never be repeated.
 */
class SendPayoutBatch implements OutboxHandler
{
    public function __construct(
        private readonly PayoutProviderManager $providers,
        private readonly PayoutFeeCalculator $fees,
        private readonly PayoutBatchState $state,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $batch = PayoutBatch::query()->find((string) ($payload['payout_batch_id'] ?? ''));

        if ($batch !== null) {
            $this->send($batch);
        }
    }

    public function send(PayoutBatch $batch): PayoutBatch
    {
        if ($batch->route !== PayoutRoute::MobileMoney || $batch->status === PayoutBatchStatus::Paid) {
            return $batch;
        }

        $provider = $this->providers->active();

        $pending = PayoutItem::query()
            ->where('payout_batch_id', $batch->id)
            ->where('status', PayoutItemStatus::Pending)
            ->orderBy('sequence')
            ->get();

        foreach ($pending as $item) {
            $this->sendItem($batch, $item, $provider);
        }

        return $this->state->recompute($batch);
    }

    /**
     * Applies a provider answer to an item (also used when reconciling).
     */
    public function apply(PayoutItem $item, PayoutResult $result): void
    {
        $item->forceFill(match ($result->status) {
            ProviderPayoutStatus::Succeeded => [
                'status' => PayoutItemStatus::Succeeded,
                'provider_reference' => $result->providerReference,
                'settled_at' => now(),
                'last_error' => null,
            ],
            ProviderPayoutStatus::Failed => [
                'status' => PayoutItemStatus::Failed,
                'provider_reference' => $result->providerReference,
                'settled_at' => now(),
                'last_error' => $result->failureCode ?? 'provider_failure',
            ],
            ProviderPayoutStatus::Processing => [
                'status' => PayoutItemStatus::Processing,
                'provider_reference' => $result->providerReference,
            ],
        })->save();
    }

    private function sendItem(PayoutBatch $batch, PayoutItem $item, PayoutProvider $provider): void
    {
        $claimed = $this->claim($batch, $item, $provider);

        if ($claimed === null) {
            return;
        }

        $phone = $batch->destination['phone'] ?? null;

        if (! is_string($phone) || $phone === '') {
            $claimed->forceFill(['status' => PayoutItemStatus::Failed, 'last_error' => 'destination_phone_missing', 'settled_at' => now()])->save();

            return;
        }

        $beneficiary = $batch->beneficiary;

        try {
            $result = $provider->send(new PayoutRequest(
                merchantReference: (string) $claimed->merchant_reference,
                amount: (int) $claimed->transfer_amount,
                currency: $batch->currency,
                phone: $phone,
                firstName: $beneficiary->first_name,
                lastName: $beneficiary->last_name,
                email: $beneficiary->email,
                method: $batch->method,
                country: is_string($batch->destination['country'] ?? null) ? (string) $batch->destination['country'] : 'TG',
            ));

            $this->apply($claimed, $result);
        } catch (PayoutProviderException $exception) {
            report($exception);
            $claimed->forceFill([
                'provider_reference' => $exception->providerReference,
                'last_error' => mb_substr($exception->getMessage(), 0, 255),
            ])->save();
        }
    }

    private function claim(PayoutBatch $batch, PayoutItem $item, PayoutProvider $provider): ?PayoutItem
    {
        return DB::transaction(function () use ($batch, $item, $provider): ?PayoutItem {
            $item = PayoutItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($item->status !== PayoutItemStatus::Pending) {
                return null;
            }

            try {
                $fees = $this->fees->forItem($provider->name(), $item->amount);
            } catch (PayoutException) {
                PayoutBatch::query()->whereKey($batch->id)->update([
                    'status' => PayoutBatchStatus::AwaitingDispatch->value,
                    'blocked_reason' => 'fees_not_configured',
                ]);

                return null;
            }

            $attempt = $item->attempts + 1;

            $item->forceFill([
                'status' => PayoutItemStatus::Processing,
                'attempts' => $attempt,
                'provider' => $provider->name(),
                'provider_reference' => null,
                'merchant_reference' => "{$batch->reference}-{$item->sequence}-{$attempt}",
                'fee_amount' => $fees['fee_amount'],
                'transfer_amount' => $fees['transfer_amount'],
                'submitted_at' => now(),
                'settled_at' => null,
                'last_error' => null,
            ])->save();

            PayoutBatch::query()->whereKey($batch->id)->whereNotNull('blocked_reason')->update(['blocked_reason' => null]);

            return $item;
        });
    }
}
