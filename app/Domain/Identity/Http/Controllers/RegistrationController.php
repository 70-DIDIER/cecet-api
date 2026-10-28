<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Actions\EstablishAuthentication;
use App\Domain\Identity\Actions\RegisterMember;
use App\Domain\Identity\Actions\VerifyMemberEmail;
use App\Domain\Identity\Enums\PernumStatus;
use App\Domain\Identity\Http\Requests\RegisterMemberRequest;
use App\Domain\Identity\Http\Resources\OtpChallengeResource;
use App\Domain\Identity\Http\Resources\UserResource;
use App\Domain\Membership\MembershipFee;
use App\Domain\Payment\PaymentFeeCalculator;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegistrationController
{
    /**
     * Check that an e-mail and a Pernum are still free, before the rest of the registration form is filled in.
     *
     * Same rules and same validation errors as the registration itself, so the form can stop on the first step.
     */
    public function check(Request $request): JsonResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'pernum' => trim((string) $request->input('pernum')),
        ]);

        $request->validate([
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'pernum' => [
                'required', 'string', 'regex:'.RegisterMemberRequest::PERNUM_FORMAT,
                Rule::unique('pernum_identities', 'pernum')->where(fn ($query) => $query->where('status', '<>', PernumStatus::Revoked->value)),
            ],
        ], attributes: ['email' => 'e-mail', 'pernum' => 'Pernum']);

        return response()->json(['data' => ['available' => true]]);
    }

    /**
     * Create dossier and email challenge, without revealing fees before KYC.
     */
    public function store(RegisterMemberRequest $request, RegisterMember $register): JsonResponse
    {
        ['user' => $user, 'challenge' => $challenge] = $register->handle($request->validated());
        return response()->json(['data' => [
            'user' => new UserResource($user->load(['organization', 'pernumIdentities'])),
            'verification' => new OtpChallengeResource($challenge),
        ]], 201);
    }

    /**
     * Verify the email address with the received code.
     *
     * The account moves to PENDING_KYC before any payment.
     */
    public function verifyEmail(Request $request, VerifyMemberEmail $verify, EstablishAuthentication $establish): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'ulid'],
            'code' => ['required', 'string', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        return $establish->handle($request, $verify->handle($validated['challenge_id'], $validated['code']), $validated['device_name'] ?? 'web');
    }
}
