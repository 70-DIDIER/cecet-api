<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Otp\OtpService;
use App\Models\User;

/**
 * Second login step: a valid login OTP lets the account in.
 */
class CompleteLogin
{
    public function __construct(
        private readonly OtpService $otp,
    ) {}

    public function handle(string $challengeId, string $code): User
    {
        $user = $this->otp->verify($challengeId, $code, OtpPurpose::Login)->user;

        if ($user->isSuperadmin() && config('superadmin.enabled')) {
            throw IdentityException::invalidCredentials();
        }

        if (! $user->status->canAuthenticate()) {
            throw IdentityException::accountDisabled();
        }

        return $user;
    }
}
