<?php

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Actions\EstablishAuthentication;
use App\Domain\Identity\Actions\RegisterMember;
use App\Domain\Identity\Actions\VerifyMemberEmail;
use App\Domain\Identity\Http\Requests\RegisterMemberRequest;
use App\Domain\Identity\Http\Resources\OtpChallengeResource;
use App\Domain\Identity\Http\Resources\UserResource;
use App\Domain\Membership\MembershipFee;
use App\Domain\Payment\PaymentFeeCalculator;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationController
{
    /**
     * Register a natural or legal person.
     *
     * The account is created in PENDING_EMAIL_VERIFICATION and a verification code is sent by email.
     * The response gives the membership fee computed by the server and, when the payment provider is configured,
     * the amount to pay with transaction fees.
     */
    public function store(RegisterMemberRequest $request, RegisterMember $register, MembershipFee $fees, PaymentFeeCalculator $feeCalculator, SettingsService $settings): JsonResponse
    {
        ['user' => $user, 'challenge' => $challenge] = $register->handle($request->validated());
        $fee = $fees->for($user);

        try {
            $breakdown = $feeCalculator->forNet($settings->string('payment.inbound_provider') ?? '', $fee['amount']);
            $quote = ['net_amount' => $breakdown->net, 'fee_amount' => $breakdown->fee, 'total_amount' => $breakdown->total];
        } catch (ApiException) {
            $quote = null;
        }

        return response()->json([
            'data' => [
                'user' => new UserResource($user->load(['organization', 'pernumIdentities'])),
                'verification' => new OtpChallengeResource($challenge),
                'membership_fee' => [
                    'amount' => $fee['amount'],
                    'currency' => MembershipFee::CURRENCY,
                    'payment_quote' => $quote,
                ],
            ],
        ], 201);
    }

    /**
     * Verify the email address with the received code.
     *
     * The account moves to PENDING_PAYMENT and a session is opened to pay the membership fee.
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
