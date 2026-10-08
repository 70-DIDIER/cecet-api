<?php

namespace App\Domain\Merchant\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a merchant chose to publish in the public directory.
 *
 * @property string $user_id
 * @property bool $listed
 * @property string $display_name
 * @property string|null $activity
 * @property string|null $city
 * @property string|null $address
 * @property string|null $public_phone
 * @property string|null $description
 * @property Carbon|null $listed_at
 * @property-read User $merchant
 */
#[Fillable(['user_id', 'listed', 'display_name', 'activity', 'city', 'address', 'public_phone', 'description', 'listed_at'])]
class MerchantDirectoryProfile extends Model
{
    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->user_id,
            'name' => $this->display_name,
            'activity' => $this->activity,
            'city' => $this->city,
            'address' => $this->address,
            'phone' => $this->public_phone,
            'description' => $this->description,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'listed' => 'boolean',
            'listed_at' => 'datetime',
        ];
    }
}
