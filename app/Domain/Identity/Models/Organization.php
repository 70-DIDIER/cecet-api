<?php

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legal person represented by a member account.
 *
 * @property string $id
 * @property string $user_id
 * @property string $legal_name
 * @property string $legal_form
 * @property string|null $rccm
 * @property string|null $nif
 */
#[Fillable(['user_id', 'legal_name', 'legal_form', 'rccm', 'nif'])]
class Organization extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function representative(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
