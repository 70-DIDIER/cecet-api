<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\IdentityDocumentType;
use App\Domain\Identity\Enums\IdentityVerificationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One submission of an identity document and a live selfie by a member, reviewed by the CECT.
 * Files live on the private disk and are only served to authorised staff.
 *
 * @property string $id
 * @property string $user_id
 * @property IdentityDocumentType $document_type
 * @property string|null $document_number
 * @property string $front_path
 * @property string|null $back_path
 * @property string $selfie_path
 * @property IdentityVerificationStatus $status
 * @property string|null $rejection_reason
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'document_type', 'document_number', 'front_path', 'back_path', 'selfie_path', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at'])]
class IdentityVerification extends Model
{
    use HasUlids;

    public const DISK = 'local';

    public const FILES = ['front', 'back', 'selfie'];

    protected function casts(): array
    {
        return [
            'document_type' => IdentityDocumentType::class,
            'status' => IdentityVerificationStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function pathOf(string $file): ?string
    {
        return match ($file) {
            'front' => $this->front_path,
            'back' => $this->back_path,
            'selfie' => $this->selfie_path,
            default => null,
        };
    }
}
