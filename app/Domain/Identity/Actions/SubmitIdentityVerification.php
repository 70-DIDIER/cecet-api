<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\IdentityDocumentType;
use App\Domain\Identity\Enums\IdentityVerificationStatus;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A member sends an identity document and a selfie taken with the camera, which the CECT then reviews.
 *
 * A new submission is accepted only when none is pending and the identity is not verified yet, so a rejected
 * member can simply try again. The files are stored on the private disk, never under a public URL.
 */
class SubmitIdentityVerification
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(User $member, IdentityDocumentType $type, ?string $documentNumber, UploadedFile $front, ?UploadedFile $back, UploadedFile $selfie): IdentityVerification
    {
        if ($member->hasVerifiedIdentity()) {
            throw IdentityException::identityAlreadyVerified();
        }

        $id = (string) Str::ulid();
        $directory = "identity-verifications/{$member->id}/{$id}";
        $disk = Storage::disk(IdentityVerification::DISK);

        $paths = [
            'front_path' => $front->storeAs($directory, 'front.'.$front->extension(), IdentityVerification::DISK),
            'back_path' => $back?->storeAs($directory, 'back.'.$back->extension(), IdentityVerification::DISK),
            'selfie_path' => $selfie->storeAs($directory, 'selfie.'.$selfie->extension(), IdentityVerification::DISK),
        ];

        try {
            return DB::transaction(function () use ($member, $type, $documentNumber, $id, $paths): IdentityVerification {
                User::query()->lockForUpdate()->findOrFail($member->id);

                $pending = IdentityVerification::query()
                    ->where('user_id', $member->id)
                    ->where('status', IdentityVerificationStatus::Pending)
                    ->exists();

                if ($pending) {
                    throw IdentityException::identityVerificationPending();
                }

                $verification = new IdentityVerification([
                    'user_id' => $member->id,
                    'document_type' => $type,
                    'document_number' => $documentNumber,
                    ...$paths,
                    'status' => IdentityVerificationStatus::Pending,
                ]);
                $verification->id = $id;
                $verification->save();

                $this->auditLogger->record('identity_verifications.submitted', $verification, after: [
                    'document_type' => $type->value,
                    'has_back_side' => $paths['back_path'] !== null,
                ], actor: $member);

                return $verification;
            });
        } catch (Throwable $exception) {
            $disk->deleteDirectory($directory);

            throw $exception;
        }
    }
}
