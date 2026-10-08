<?php

namespace App\Domain\Exchange\Http\Resources;

use App\Domain\Exchange\Models\ExchangeRequest;
use App\Support\YemQuantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExchangeRequest
 */
class ExchangeRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $batch = $this->relationLoaded('payoutBatch') ? $this->payoutBatch : null;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'yem_confirmed' => $this->status->isYemConfirmed(),
            'user_pack_id' => $this->user_pack_id,
            'quantity_requested' => $this->quantity_requested,
            'quantity_received' => $this->quantity_received,
            'rate_xof_per_yem' => $this->rate_xof_per_yem,
            'estimated_amount_xof' => YemQuantity::toXof($this->quantity_requested, $this->rate_xof_per_yem),
            'amount_due_xof' => $this->amount_due_xof,
            'transfer_instructions' => [
                'send_to_pernum' => $this->cect_pernum,
                'from_pernum' => $this->source_pernum,
                'quantity' => $this->quantity_requested,
                'reference' => $this->reference,
                // The QR only pre-fills the transfer in the YEM wallet: it never proves that YEM were sent.
                'qr_payload' => "CECT|1|{$this->cect_pernum}|{$this->reference}|{$this->quantity_requested}",
            ],
            'payout_destination' => [
                'method' => $this->payout_destination['method'] ?? null,
                'phone' => $this->payout_destination['phone'] ?? null,
            ],
            'payout' => $batch === null ? null : [
                'reference' => $batch->reference,
                'status' => $batch->status->value,
                'total_amount' => $batch->total_amount,
                'paid_amount' => $batch->paid_amount,
                'remaining_amount' => $batch->remaining_amount,
            ],
            'member_transfer_reference' => $this->member_transfer_reference,
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
            'declared_at' => $this->declared_at?->toIso8601ZuluString(),
            'validated_at' => $this->validated_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'validation_events' => $this->whenLoaded('validationEvents', fn () => $this->validationEvents->map(fn ($event): array => [
                'action' => $event->action->value,
                'quantity_received' => $event->quantity_received,
                'external_reference' => $event->external_reference,
                'comment' => $event->comment,
                'actor_id' => $event->actor_id,
                'created_at' => $event->created_at->toIso8601ZuluString(),
            ])->all()),
        ];
    }
}
