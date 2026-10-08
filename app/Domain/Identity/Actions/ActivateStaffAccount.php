<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Otp\OtpService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lets a newly created internal account choose its password with the activation code received by email.
 */
class ActivateStaffAccount
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(string $email, string $code, string $password): User
    {
        $user = User::query()
            ->where('email', mb_strtolower(trim($email)))
            ->where('kind', UserKind::Staff)
            ->where('status', UserStatus::PendingActivation)
            ->first() ?? throw IdentityException::otpInvalid();

        $this->otp->verifyLatest($user, $code, OtpPurpose::AccountActivation);

        DB::transaction(function () use ($user, $password): void {
            $user->forceFill([
                'password' => $password,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ])->save();

            $this->auditLogger->record(
                action: 'staff.activated',
                subject: $user,
                before: ['status' => UserStatus::PendingActivation->value],
                after: ['status' => UserStatus::Active->value],
                actor: $user,
            );
        });

        return $user;
    }
}
