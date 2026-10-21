<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Actions\ActivateStaffAccount;
use App\Domain\Identity\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffActivationController
{
    /**
     * Activate an internal account with the code received by email and choose its password.
     */
    public function __invoke(Request $request, ActivateStaffAccount $activate, PasswordPolicy $passwordPolicy): JsonResponse
    {
        // Three-guardian staff login replaces the legacy activation/password
        // route; leaving it available would silently reenable old credentials.
        abort_if(config('staff_guardian.enabled'), 404);
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'confirmed', $passwordPolicy->rule()],
        ]);

        $activate->handle($validated['email'], $validated['code'], $validated['password']);

        return response()->json(['data' => ['status' => 'activated']]);
    }
}
