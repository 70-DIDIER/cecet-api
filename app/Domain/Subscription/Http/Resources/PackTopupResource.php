<?php

namespace App\Domain\Subscription\Http\Resources;

use App\Domain\Subscription\Models\PackTopup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PackTopup
 */
class PackTopupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_pack_id' => $this->user_pack_id,
            'status' => $this->status->value,
            'amount_xof' => $this->amount_xof,
            'rate_xof_per_yem' => $this->rate_xof_per_yem,
            'cashback_bps' => $this->cashback_bps,
            'yem_base' => $this->yem_base,
            'yem_cashback' => $this->yem_cashback,
            'quantity' => $this->quantity,
            'credited_at' => $this->credited_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
