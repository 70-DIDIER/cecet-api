<?php

namespace App\Domain\Subscription\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One allowance cycle of a periodic pack. Generated at activation, credited when it starts.
 *
 * @property string $id
 * @property string $user_pack_id
 * @property int $number
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $quota
 * @property Carbon|null $credited_at
 * @property Carbon|null $closed_at
 * @property-read UserPack $userPack
 */
#[Fillable(['user_pack_id', 'number', 'starts_at', 'ends_at', 'quota', 'credited_at', 'closed_at'])]
class PackPeriod extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<UserPack, $this>
     */
    public function userPack(): BelongsTo
    {
        return $this->belongsTo(UserPack::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'credited_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
