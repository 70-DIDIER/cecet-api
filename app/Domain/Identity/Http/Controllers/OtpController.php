<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Actions\ResendOtp;
use App\Domain\Identity\Http\Resources\OtpChallengeResource;
use Illuminate\Http\Request;

class OtpController
{
    /**
     * Send a new code for a pending challenge.
     *
     * The previous code stops working. Subject to a cooldown and an hourly cap.
     */
    public function resend(Request $request, ResendOtp $resend): OtpChallengeResource
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'ulid'],
        ]);

        return new OtpChallengeResource($resend->handle($validated['challenge_id']));
    }
}
