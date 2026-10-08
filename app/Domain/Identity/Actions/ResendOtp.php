<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Identity\Otp\OtpService;

/**
 * Sends a new code for the same user and purpose as a pending challenge, subject to throttling.
 */
class ResendOtp
{
    public function __construct(private readonly OtpService $otp) {}

    public function handle(string $challengeId): OtpChallenge
    {
        $challenge = OtpChallenge::query()->whereKey($challengeId)->whereNull('consumed_at')->first()
            ?? throw IdentityException::otpInvalid();

        return $this->otp->issue($challenge->user, $challenge->purpose);
    }
}
