<?php

namespace App\Domain\Payout\Http\Resources;

use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Payout\Models\ManualSettlement;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\Models\PayoutItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Accounting view of a payout. "yem_confirmed" is a read-only projection of the Validator's decision.
 *
 * @mixin PayoutBatch
 */
class PayoutBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $exchange = $this->payable instanceof ExchangeRequest ? $this->payable : null;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'blocked_reason' => $this->blocked_reason,
            'route' => $this->route->value,
            'method' => $this->method,
            'destination' => $this->destination,
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->paid_amount,
            'remaining_amount' => $this->remaining_amount,
            'currency' => $this->currency,
            'beneficiary' => [
                'id' => $this->beneficiary->id,
                'name' => $this->beneficiary->full_name,
                'member_number' => $this->beneficiary->member_number,
            ],
            'operation' => $exchange === null ? null : [
                'type' => 'exchange',
                'reference' => $exchange->reference,
                'quantity_received' => $exchange->quantity_received,
                'rate_xof_per_yem' => $exchange->rate_xof_per_yem,
                'yem_confirmed' => $exchange->status->isYemConfirmed(),
                'validated_by' => $exchange->validated_by,
                'validated_at' => $exchange->validated_at?->toIso8601ZuluString(),
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (PayoutItem $item): array => [
                'id' => $item->id,
                'sequence' => $item->sequence,
                'kind' => $item->kind->value,
                'amount' => $item->amount,
                'fee_amount' => $item->fee_amount,
                'transfer_amount' => $item->transfer_amount,
                'status' => $item->status->value,
                'provider' => $item->provider,
                'provider_reference' => $item->provider_reference,
                'attempts' => $item->attempts,
                'last_error' => $item->last_error,
                'submitted_at' => $item->submitted_at?->toIso8601ZuluString(),
                'settled_at' => $item->settled_at?->toIso8601ZuluString(),
            ])->all()),
            'manual_settlements' => $this->whenLoaded('manualSettlements', fn () => $this->manualSettlements->map(fn (ManualSettlement $settlement): array => [
                'id' => $settlement->id,
                'amount' => $settlement->amount,
                'method' => $settlement->method,
                'reference' => $settlement->reference,
                'status' => $settlement->status->value,
                'recorded_by' => $settlement->recorded_by,
                'decided_by' => $settlement->decided_by,
                'paid_at' => $settlement->paid_at->toIso8601ZuluString(),
            ])->all()),
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
