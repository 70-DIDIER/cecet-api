<?php

namespace App\Domain\Identity\Otp;

use App\Domain\Identity\Models\OtpChallenge;

/**
 * Delivery channel of one-time codes. Email is the only channel in V1.
 */
interface OtpChannel
{
    public function send(OtpChallenge $challenge, string $code): void;
}
