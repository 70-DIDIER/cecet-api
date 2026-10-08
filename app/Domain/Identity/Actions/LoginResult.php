<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\OtpChallenge;
use App\Models\User;

/**
 * Outcome of the credential check: either the account may be authenticated now, or an OTP challenge must be completed.
 */
final readonly class LoginResult
{
    private function __construct(
        public User $user,
        public ?OtpChallenge $challenge = null,
    ) {}

    public static function authenticated(User $user): self
    {
        return new self($user);
    }

    public static function otpRequired(User $user, OtpChallenge $challenge): self
    {
        return new self($user, $challenge);
    }

    public function requiresOtp(): bool
    {
        return $this->challenge !== null;
    }
}
