<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\IdentityVerificationStatus;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\IdentityVerification;
use App\Domain\Notification\Notifier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A staff member approves or rejects a pending identity verification.
 *
 * Approval marks the member's identity as verified, which opens pack subscriptions. A rejection keeps the reason,
 * shown to the member, who may then send new documents. The member is informed either way.
 */
class ReviewIdentityVerification
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly Notifier $notifier,
    ) {}

    public function approve(User $reviewer, IdentityVerification $verification): IdentityVerification
    {
        $verification = DB::transaction(function () use ($reviewer, $verification): IdentityVerification {
            $verification = $this->lockPending($verification);

            $verification->forceFill([
                'status' => IdentityVerificationStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();
            $verification->user->forceFill(['identity_verified_at' => now()])->save();

            $this->auditLogger->record('identity_verifications.approved', $verification,
                ['status' => IdentityVerificationStatus::Pending->value],
                ['status' => IdentityVerificationStatus::Approved->value],
                metadata: ['user_id' => $verification->user_id],
                actor: $reviewer);

            return $verification;
        });

        $this->notifier->notify($verification->user, 'identity.approved');

        return $verification;
    }

    public function reject(User $reviewer, IdentityVerification $verification, string $reason): IdentityVerification
    {
        $verification = DB::transaction(function () use ($reviewer, $verification, $reason): IdentityVerification {
            $verification = $this->lockPending($verification);

            $verification->forceFill([
                'status' => IdentityVerificationStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            $this->auditLogger->record('identity_verifications.rejected', $verification,
                ['status' => IdentityVerificationStatus::Pending->value],
                ['status' => IdentityVerificationStatus::Rejected->value],
                reason: $reason,
                metadata: ['user_id' => $verification->user_id],
                actor: $reviewer);

            return $verification;
        });

        $this->notifier->notify($verification->user, 'identity.rejected', ['reason' => $reason]);

        return $verification;
    }

    private function lockPending(IdentityVerification $verification): IdentityVerification
    {
        $verification = IdentityVerification::query()->with('user')->lockForUpdate()->findOrFail($verification->id);

        if ($verification->status !== IdentityVerificationStatus::Pending) {
            throw IdentityException::identityVerificationNotPending();
        }

        return $verification;
    }
}
