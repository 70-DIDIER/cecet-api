<?php

namespace App\Domain\Membership\Http\Resources;

use App\Domain\Membership\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Membership
 */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'fee_amount' => $this->fee_amount,
            'fee_currency' => $this->fee_currency,
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'activated_at' => $this->activated_at?->toIso8601ZuluString(),
            'activation_mode' => $this->activation_mode?->value,
        ];
    }
}
