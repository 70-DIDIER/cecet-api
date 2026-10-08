<?php

namespace App\Domain\Subscription\Models;

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Catalog\Models\PackVersion;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Quota\Models\QuotaAccount;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A pack held by a member: the exact version bought, the price paid and its lifetime.
 *
 * @property string $id
 * @property string $user_id
 * @property string $pack_plan_id
 * @property string $pack_version_id
 * @property PackFamily $family
 * @property UserPackStatus $status
 * @property int $price_xof
 * @property Carbon|null $paid_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $canceled_at
 * @property-read User $user
 * @property-read PackPlan $plan
 * @property-read PackVersion $version
 * @property-read QuotaAccount|null $account
 */
#[Fillable(['user_id', 'pack_plan_id', 'pack_version_id', 'family', 'status', 'price_xof', 'paid_at', 'activated_at', 'expires_at', 'canceled_at'])]
class UserPack extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PackPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PackPlan::class, 'pack_plan_id');
    }

    /**
     * @return BelongsTo<PackVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(PackVersion::class, 'pack_version_id');
    }

    /**
     * @return HasMany<PackPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(PackPeriod::class)->orderBy('number');
    }

    /**
     * @return HasOne<QuotaAccount, $this>
     */
    public function account(): HasOne
    {
        return $this->hasOne(QuotaAccount::class);
    }

    /**
     * @return MorphMany<PaymentIntent, $this>
     */
    public function paymentIntents(): MorphMany
    {
        return $this->morphMany(PaymentIntent::class, 'payable');
    }

    /**
     * @return HasMany<PackTopup, $this>
     */
    public function topups(): HasMany
    {
        return $this->hasMany(PackTopup::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'family' => PackFamily::class,
            'status' => UserPackStatus::class,
            'price_xof' => 'integer',
            'paid_at' => 'datetime',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }
}
