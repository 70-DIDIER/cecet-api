<?php

namespace App\Domain\Merchant\Http\Resources;

use App\Domain\Merchant\Models\MerchantPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MerchantPayment
 */
class MerchantPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'quantity' => $this->quantity,
            'payer' => $this->whenLoaded('payer', fn () => $this->party($this->payer, $this->payer_pernum)),
            'payer_pack_id' => $this->payer_pack_id,
            'merchant' => $this->whenLoaded('merchant', fn () => $this->party($this->merchant, $this->merchant_pernum)),
            'merchant_pernum' => $this->merchant_pernum,
            'outlet_label' => $this->outlet_label,
            'confirmation_mode' => $this->confirmation_mode,
            'transfer_reference' => $this->payer_transfer_reference,
            'dispute_reason' => $this->dispute_reason,
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
            'declared_at' => $this->declared_at?->toIso8601ZuluString(),
            'confirmed_at' => $this->confirmed_at?->toIso8601ZuluString(),
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function party(User $user, ?string $pernum): array
    {
        return [
            'id' => $user->id,
            'name' => $user->organization->legal_name ?? $user->full_name,
            'member_number' => $user->member_number,
            'pernum' => $pernum,
        ];
    }
}
