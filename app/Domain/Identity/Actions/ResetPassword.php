<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Otp\OtpService;
use App\Domain\Identity\SessionTerminator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Password reset by email OTP. Requests never reveal whether an account exists,
 * and a successful reset revokes every session of the account.
 */
class ResetPassword
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly SecurityEventLogger $securityLogger,
        private readonly SessionTerminator $terminator,
    ) {}

    /**
     * @param  string|null  $pernum  when given (as on the former site), it must belong to the same account
     */
    public function request(string $email, ?string $pernum = null): void
    {
        $user = $this->findEligible($email);

        if ($user !== null && $pernum !== null && ! $user->pernumIdentities()->whereRaw('lower(pernum) = ?', [mb_strtolower(trim($pernum))])->exists()) {
            $user = null;
        }

        if ($user === null) {
            $this->securityLogger->record('auth.password_reset_unknown', identifier: mb_strtolower(trim($email)));

            return;
        }

        try {
            $this->otp->issue($user, OtpPurpose::PasswordReset);
        } catch (IdentityException) {
            // Throttled: the response stays identical so the account is not revealed.
        }
    }

    public function reset(string $email, string $code, string $password): void
    {
        $user = $this->findEligible($email) ?? throw IdentityException::otpInvalid();

        $this->otp->verifyLatest($user, $code, OtpPurpose::PasswordReset);

        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password' => $password])->save();
            $this->terminator->terminateAll($user);
        });

        $this->securityLogger->record('auth.password_reset', SecuritySeverity::Warning, userId: $user->id);
    }

    private function findEligible(string $email): ?User
    {
        return User::query()
            ->where('email', mb_strtolower(trim($email)))
            ->whereIn('status', [UserStatus::PendingPayment, UserStatus::Active])
            ->first();
    }
}
