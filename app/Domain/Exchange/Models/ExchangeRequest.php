<?php

namespace App\Domain\Exchange\Models;

use App\Domain\Exchange\Enums\ExchangeKind;
use App\Domain\Exchange\Enums\ExchangeStatus;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\PayoutPayable;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * YEM a member sells to the CECT. The rate, the Pernums and the payout destination are snapshotted at creation;
 * the counter-value is computed only once the Validator has confirmed the quantity actually received.
 *
 * @property string $id
 * @property string $reference
 * @property ExchangeKind $kind
 * @property string $user_id
 * @property string $user_pack_id
 * @property ExchangeStatus $status
 * @property string $quantity_requested
 * @property string|null $quantity_received
 * @property string $rate_setting_key
 * @property int $rate_xof_per_yem
 * @property array<string,mixed>|null $pricing_snapshot
 * @property int|null $amount_due_xof
 * @property string $source_pernum
 * @property string $cect_pernum
 * @property array<string, mixed> $payout_destination
 * @property string|null $member_transfer_reference
 * @property Carbon $expires_at
 * @property Carbon|null $declared_at
 * @property Carbon|null $validated_at
 * @property string|null $validated_by
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read UserPack $userPack
 * @property-read PayoutBatch|null $payoutBatch
 */
#[Fillable([
    'reference', 'kind', 'user_id', 'user_pack_id', 'status', 'quantity_requested', 'quantity_received', 'rate_setting_key',
    'rate_xof_per_yem', 'pricing_snapshot', 'amount_due_xof', 'source_pernum', 'cect_pernum', 'payout_destination', 'member_transfer_reference',
    'expires_at', 'declared_at', 'validated_at', 'validated_by', 'closed_at',
])]
class ExchangeRequest extends Model implements PayoutPayable
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
     * @return BelongsTo<UserPack, $this>
     */
    public function userPack(): BelongsTo
    {
        return $this->belongsTo(UserPack::class);
    }

    /**
     * @return HasMany<ValidationEvent, $this>
     */
    public function validationEvents(): HasMany
    {
        return $this->hasMany(ValidationEvent::class)->orderBy('created_at');
    }

    /**
     * @return MorphOne<PayoutBatch, $this>
     */
    public function payoutBatch(): MorphOne
    {
        return $this->morphOne(PayoutBatch::class, 'payable');
    }

    /**
     * The counter-value has been fully paid: the exchange is complete.
     */
    public function onPayoutPaid(PayoutBatch $batch): void
    {
        if ($this->status === ExchangeStatus::YemReceived) {
            $this->forceFill(['status' => ExchangeStatus::Completed, 'closed_at' => now()])->save();
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ExchangeKind::class,
            'status' => ExchangeStatus::class,
            'rate_xof_per_yem' => 'integer',
            'pricing_snapshot' => 'array',
            'amount_due_xof' => 'integer',
            'payout_destination' => 'array',
            'expires_at' => 'datetime',
            'declared_at' => 'datetime',
            'validated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
