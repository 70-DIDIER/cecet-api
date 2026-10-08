<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Actions\ConfirmStepUp;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Http\Resources\OtpChallengeResource;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Otp\OtpService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StepUpController
{
    /**
     * Request a confirmation code before a sensitive action.
     */
    public function store(Request $request, OtpService $otp): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new OtpChallengeResource($otp->issue($user, OtpPurpose::StepUp)))->response()->setStatusCode(201);
    }

    /**
     * Confirm the code: the current session may perform sensitive actions for a limited time.
     */
    public function confirm(Request $request, ConfirmStepUp $confirm): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'ulid'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        $until = $confirm->handle(
            $user,
            $token instanceof PersonalAccessToken ? $token : null,
            $request->hasSession() ? $request->session() : null,
            $validated['challenge_id'],
            $validated['code'],
        );

        return response()->json(['data' => ['step_up_until' => $until->toIso8601ZuluString()]]);
    }
}
