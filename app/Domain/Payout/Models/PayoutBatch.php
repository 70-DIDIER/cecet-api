<?php

namespace App\Domain\Payout\Models;

use App\Domain\Payout\Enums\PayoutBatchStatus;
use App\Domain\Payout\Enums\PayoutRoute;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One FCFA obligation towards a beneficiary. Split into technical items, it remains a single debt:
 * paid_amount + remaining_amount = total_amount at all times (database check).
 *
 * @property string $id
 * @property string $reference
 * @property string $payable_type
 * @property string $payable_id
 * @property string $beneficiary_id
 * @property int $total_amount
 * @property int $paid_amount
 * @property int $remaining_amount
 * @property string $currency
 * @property PayoutRoute $route
 * @property string $method
 * @property array<string, mixed> $destination
 * @property PayoutBatchStatus $status
 * @property string|null $blocked_reason
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property-read User $beneficiary
 * @property-read Model $payable
 */
#[Fillable(['reference', 'payable_type', 'payable_id', 'beneficiary_id', 'total_amount', 'paid_amount', 'remaining_amount', 'currency', 'route', 'method', 'destination', 'status', 'blocked_reason', 'paid_at'])]
class PayoutBatch extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<PayoutItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PayoutItem::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<ManualSettlement, $this>
     */
    public function manualSettlements(): HasMany
    {
        return $this->hasMany(ManualSettlement::class)->orderBy('created_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_amount' => 'integer',
            'paid_amount' => 'integer',
            'remaining_amount' => 'integer',
            'route' => PayoutRoute::class,
            'destination' => 'array',
            'status' => PayoutBatchStatus::class,
            'paid_at' => 'datetime',
        ];
    }
}
