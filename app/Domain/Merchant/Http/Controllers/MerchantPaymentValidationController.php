<?php

namespace App\Domain\Merchant\Http\Controllers;

use App\Domain\Merchant\Actions\MerchantPaymentService;
use App\Domain\Merchant\Http\Resources\MerchantPaymentResource;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The Validator's decision on a merchant payment: declared payments in validator mode, disputed ones in any mode.
 */
class MerchantPaymentValidationController
{
    public function __construct(private readonly MerchantPaymentService $payments) {}

    public function show(MerchantPayment $payment): MerchantPaymentResource
    {
        return new MerchantPaymentResource($payment->load(['payer.organization', 'merchant.organization']));
    }

    public function confirm(Request $request, MerchantPayment $payment): MerchantPaymentResource
    {
        return new MerchantPaymentResource($this->payments->confirmByValidator($this->user($request), $payment)->load(['payer.organization', 'merchant.organization']));
    }

    public function reject(Request $request, MerchantPayment $payment): MerchantPaymentResource
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return new MerchantPaymentResource($this->payments->rejectByValidator($this->user($request), $payment, $validated['reason'])->load(['payer.organization', 'merchant.organization']));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
