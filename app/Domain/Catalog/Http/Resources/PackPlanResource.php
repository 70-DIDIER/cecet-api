<?php

namespace App\Domain\Catalog\Http\Resources;

use App\Domain\Catalog\Models\PackPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PackPlan
 */
class PackPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'family' => $this->family->value,
            'code' => $this->code,
            'name' => $this->name,
            'level' => $this->level,
            'description' => $this->description,
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion === null ? null : new PackVersionResource($this->currentVersion)),
            'versions' => $this->whenLoaded('versions', fn () => PackVersionResource::collection($this->versions)),
        ];
    }
}
