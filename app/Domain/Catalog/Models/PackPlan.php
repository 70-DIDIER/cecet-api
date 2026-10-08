<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Enums\PackPlanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A commercial pack (e.g. V3, C5, M2) with a lifecycle. Terms live in immutable versions.
 *
 * @property string $id
 * @property PackFamily $family
 * @property string $code
 * @property string $name
 * @property int|null $level
 * @property string|null $description
 * @property PackPlanStatus $status
 * @property Carbon|null $published_at
 * @property string|null $created_by
 * @property-read PackVersion|null $currentVersion
 */
#[Fillable(['family', 'code', 'name', 'level', 'description', 'status', 'published_at', 'created_by'])]
class PackPlan extends Model
{
    use HasUlids;

    /**
     * @return HasMany<PackVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PackVersion::class)->orderByDesc('version');
    }

    /**
     * The version offered to new subscribers: the highest published version.
     *
     * @return HasOne<PackVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(PackVersion::class)->ofMany(
            ['version' => 'max'],
            fn ($query) => $query->whereNotNull('published_at'),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'family' => PackFamily::class,
            'level' => 'integer',
            'status' => PackPlanStatus::class,
            'published_at' => 'datetime',
        ];
    }
}
