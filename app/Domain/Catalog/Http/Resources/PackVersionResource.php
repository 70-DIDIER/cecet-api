<?php

namespace App\Domain\Catalog\Http\Resources;

use App\Domain\Catalog\Models\PackVersion;
use App\Support\YemQuantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PackVersion
 */
class PackVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $periodic = $this->isPeriodic();

        return [
            'id' => $this->id,
            'version' => $this->version,
            'price_xof' => $this->price_xof,
            'currency' => $this->currency,
            'period' => $periodic ? [
                'unit' => $this->period_unit?->value,
                'every' => $this->period_every,
                'cycles' => $this->period_cycles,
            ] : null,
            'quota_per_period' => $this->quota_per_period,
            'total_quota' => $periodic && $this->quota_per_period !== null && $this->period_cycles !== null
                ? YemQuantity::times($this->quota_per_period, $this->period_cycles)
                : null,
            'cumulative' => $periodic ? $this->cumulative : null,
            'end_of_term' => $this->end_of_term?->value,
            'min_operation' => $this->min_operation,
            'max_operation' => $this->max_operation,
            'eligible_person_types' => $this->eligible_person_types,
            'rules' => (object) $this->rules,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'reason' => $this->when($request->user()?->isStaff() === true, $this->reason),
        ];
    }
}
