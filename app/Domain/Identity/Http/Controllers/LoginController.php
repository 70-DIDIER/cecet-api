<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Actions\AuthenticateUser;
use App\Domain\Identity\Actions\CompleteLogin;
use App\Domain\Identity\Actions\EstablishAuthentication;
use App\Domain\Identity\Http\Resources\OtpChallengeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginController
{
    /**
     * Log in with a Pernum or an e-mail address, and a password.
     *
     * From the React site the response opens an HttpOnly session cookie; other clients receive a bearer token.
     * Internal accounts (and members, when configured) first receive an OTP challenge to complete (202).
     */
    public function store(Request $request, AuthenticateUser $authenticate, EstablishAuthentication $establish): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $authenticate->handle((string) ($validated['identifier'] ?? $validated['email']), $validated['password']);

        if ($result->challenge !== null) {
            return response()->json([
                'data' => ['status' => 'otp_required', ...(new OtpChallengeResource($result->challenge))->resolve($request)],
            ], 202);
        }

        return $establish->handle($request, $result->user, $validated['device_name'] ?? 'web');
    }

    /**
     * Complete a login with the code received by email.
     */
    public function verifyOtp(Request $request, CompleteLogin $completeLogin, EstablishAuthentication $establish): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'ulid'],
            'code' => ['required', 'string', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        return $establish->handle($request, $completeLogin->handle($validated['challenge_id'], $validated['code']), $validated['device_name'] ?? 'web');
    }
}
