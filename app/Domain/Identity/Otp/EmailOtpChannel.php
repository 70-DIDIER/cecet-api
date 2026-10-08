<?php

namespace App\Domain\Identity\Otp;

use App\Domain\Identity\Mail\OtpCodeMail;
use App\Domain\Identity\Models\OtpChallenge;
use Illuminate\Support\Facades\Mail;

class EmailOtpChannel implements OtpChannel
{
    public function send(OtpChallenge $challenge, string $code): void
    {
        Mail::to($challenge->sent_to)->queue(new OtpCodeMail(
            purpose: $challenge->purpose,
            code: $code,
            expiresAt: $challenge->expires_at->toImmutable(),
        ));
    }
}
