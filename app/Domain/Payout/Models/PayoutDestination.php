<?php

namespace App\Domain\Payout\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a member wants to receive counter-values. Changing it requires a step-up confirmation.
 *
 * @property string $id
 * @property string $user_id
 * @property string $method
 * @property string $channel
 * @property string $country
 * @property string|null $phone
 * @property string|null $bank_name
 * @property string|null $account_holder
 * @property string|null $account_number
 */
#[Fillable(['user_id', 'method', 'channel', 'country', 'phone', 'bank_name', 'account_holder', 'account_number'])]
class PayoutDestination extends Model
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
     * Frozen copy stored on each operation, so a later change never redirects money already owed.
     *
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        return [
            'method' => $this->method,
            'channel' => $this->channel,
            'country' => $this->country,
            'phone' => $this->phone,
            'bank_name' => $this->bank_name,
            'account_holder' => $this->account_holder,
            'account_number' => $this->account_number,
        ];
    }
}
