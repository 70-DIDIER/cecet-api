<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Actions\ResetPassword;
use App\Domain\Identity\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordResetController
{
    /**
     * Request a password reset code.
     *
     * The response is identical whether or not the account exists.
     */
    public function forgot(Request $request, ResetPassword $reset): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'pernum' => ['nullable', 'string', 'max:64'],
        ]);

        $reset->request($validated['email'], $validated['pernum'] ?? null);

        return response()->json(['data' => ['status' => 'requested']], 202);
    }

    /**
     * Set a new password with the received code. Every session of the account is revoked.
     */
    public function reset(Request $request, ResetPassword $reset, PasswordPolicy $passwordPolicy): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'confirmed', $passwordPolicy->rule()],
        ]);

        $reset->reset($validated['email'], $validated['code'], $validated['password']);

        return response()->json(['data' => ['status' => 'password_reset']]);
    }
}
