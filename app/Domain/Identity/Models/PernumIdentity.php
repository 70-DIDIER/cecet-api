<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\PernumKind;
use App\Domain\Identity\Enums\PernumStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pernum (YEM ecosystem identifier) declared by a member.
 *
 * @property string $id
 * @property string $user_id
 * @property string $pernum
 * @property PernumKind $kind
 * @property PernumStatus $status
 * @property Carbon|null $revoked_at
 */
#[Fillable(['user_id', 'pernum', 'kind', 'status', 'revoked_at'])]
class PernumIdentity extends Model
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PernumKind::class,
            'status' => PernumStatus::class,
            'revoked_at' => 'datetime',
        ];
    }
}
