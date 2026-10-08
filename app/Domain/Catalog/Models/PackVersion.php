<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\EndOfTermPolicy;
use App\Domain\Catalog\Enums\PeriodUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Terms of a pack at a point in time. Once published a version never changes (enforced by the database):
 * packs already purchased keep the exact conditions they were bought with.
 *
 * @property string $id
 * @property string $pack_plan_id
 * @property int $version
 * @property int $price_xof
 * @property string $currency
 * @property PeriodUnit|null $period_unit
 * @property int|null $period_every
 * @property int|null $period_cycles
 * @property string|null $quota_per_period
 * @property bool $cumulative
 * @property EndOfTermPolicy|null $end_of_term
 * @property string|null $min_operation
 * @property string|null $max_operation
 * @property list<string> $eligible_person_types
 * @property array<string, mixed> $rules
 * @property string $reason
 * @property string|null $created_by
 * @property Carbon|null $published_at
 * @property Carbon $created_at
 * @property-read PackPlan $plan
 */
#[Fillable([
    'pack_plan_id', 'version', 'price_xof', 'currency', 'period_unit', 'period_every', 'period_cycles', 'quota_per_period',
    'cumulative', 'end_of_term', 'min_operation', 'max_operation', 'eligible_person_types', 'rules', 'reason', 'created_by',
    'published_at', 'created_at',
])]
class PackVersion extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return BelongsTo<PackPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PackPlan::class, 'pack_plan_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isPeriodic(): bool
    {
        return $this->period_unit !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'price_xof' => 'integer',
            'period_unit' => PeriodUnit::class,
            'period_every' => 'integer',
            'period_cycles' => 'integer',
            'cumulative' => 'boolean',
            'end_of_term' => EndOfTermPolicy::class,
            'eligible_person_types' => 'array',
            'rules' => 'array',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
