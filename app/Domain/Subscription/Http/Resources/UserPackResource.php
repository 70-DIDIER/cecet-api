<?php

namespace App\Domain\Subscription\Http\Resources;

use App\Domain\Catalog\Http\Resources\PackVersionResource;
use App\Domain\Subscription\Models\UserPack;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserPack
 */
class UserPackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'family' => $this->family->value,
            'plan' => [
                'id' => $this->plan->id,
                'code' => $this->plan->code,
                'name' => $this->plan->name,
            ],
            'price_xof' => $this->price_xof,
            'version' => new PackVersionResource($this->version),
            'balance' => $this->whenLoaded('account', fn () => $this->account === null ? null : [
                'available' => $this->account->available,
                'reserved' => $this->account->reserved,
                'consumed' => $this->account->consumed,
            ]),
            'periods' => $this->whenLoaded('periods', fn () => $this->periods->map(fn ($period): array => [
                'number' => $period->number,
                'starts_at' => $period->starts_at->toIso8601ZuluString(),
                'ends_at' => $period->ends_at->toIso8601ZuluString(),
                'quota' => $period->quota,
                'credited' => $period->credited_at !== null,
            ])->all()),
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'activated_at' => $this->activated_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'canceled_at' => $this->canceled_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
