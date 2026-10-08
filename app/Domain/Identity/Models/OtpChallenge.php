<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-time code sent by email for a single purpose.
 *
 * @property string $id
 * @property string $user_id
 * @property OtpPurpose $purpose
 * @property string $code_hash
 * @property string $sent_to
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $superseded_at
 * @property string|null $ip_address
 * @property Carbon $created_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'purpose', 'code_hash', 'sent_to', 'attempts', 'max_attempts', 'expires_at', 'consumed_at', 'superseded_at', 'ip_address'])]
#[Hidden(['code_hash'])]
class OtpChallenge extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->superseded_at === null
            && $this->expires_at->isFuture();
    }

    public function isLocked(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
