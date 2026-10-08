<?php

namespace App\Domain\Merchant\Models;

use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A merchant QR code: an opaque token only. Scanning it never moves YEM and never proves a transfer.
 *
 * @property string $id
 * @property string $user_id
 * @property string $user_pack_id
 * @property string $token
 * @property string|null $outlet_label
 * @property int $version
 * @property string $status
 * @property string|null $replaces_id
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property Carbon $created_at
 * @property-read User $merchant
 * @property-read UserPack $userPack
 */
#[Fillable(['user_id', 'user_pack_id', 'token', 'outlet_label', 'version', 'status', 'replaces_id', 'revoked_at', 'revocation_reason'])]
#[Hidden(['token'])]
class MerchantQrCode extends Model
{
    use HasUlids;

    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    /**
     * @return BelongsTo<User, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<UserPack, $this>
     */
    public function userPack(): BelongsTo
    {
        return $this->belongsTo(UserPack::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * What the printed QR encodes: a CECT link carrying the opaque token.
     */
    public function payload(): string
    {
        return rtrim((string) config('app.url'), '/').'/pay/m/'.$this->token;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }
}
