<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Otp\OtpService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Confirms the email of a newly registered member, moves the account to PENDING_KYC
 * and opens a session so the member can submit identity documents.
 */
class VerifyMemberEmail
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(string $challengeId, string $code): User
    {
        $user = $this->otp->verify($challengeId, $code, OtpPurpose::EmailVerification)->user;

        DB::transaction(function () use ($user): void {
            if ($user->status !== UserStatus::PendingEmailVerification) {
                return;
            }

            $user->forceFill([
                'status' => UserStatus::PendingKyc,
                'email_verified_at' => now(),
            ])->save();

            $this->auditLogger->record(
                action: 'members.email_verified',
                subject: $user,
                before: ['status' => UserStatus::PendingEmailVerification->value],
                after: ['status' => UserStatus::PendingKyc->value],
                actor: $user,
            );
        });

        return $user;
    }
}
