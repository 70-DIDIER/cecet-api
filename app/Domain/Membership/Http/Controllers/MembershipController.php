<?php

namespace App\Domain\Membership\Http\Controllers;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Membership\Actions\DemoActivateMembership;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Http\Resources\MembershipResource;
use App\Domain\Membership\MembershipFee;
use App\Domain\Membership\Models\Membership;
use App\Domain\Payment\Actions\ManualPaymentDesk;
use App\Domain\Payment\Actions\RefreshPaymentAttempt;
use App\Domain\Payment\Actions\StartMembershipPayment;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Payment\Http\Resources\PaymentAttemptResource;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\PaymentCheckout;
use App\Domain\Payment\PaymentFeeCalculator;
use App\Domain\Payment\Providers\Fake\FakePaymentProvider;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Membership of the authenticated member: status, payment attempts, reconciliation.
 */
class MembershipController
{
    /**
     * Membership status, the fee due and the amount to pay.
     *
     * `fee_due` is what the cooperative must receive. `payment_quote` adds the provider's transaction fees,
     * borne by the member, to give the total charged. Everything is computed by the server; the membership row
     * only exists once a payment has been started.
     */
    public function show(Request $request, MembershipFee $fees, PaymentFeeCalculator $feeCalculator, PaymentCheckout $checkout, SettingsService $settings): JsonResponse
    {
        $member = $this->member($request);
        $membership = Membership::query()->where('user_id', $member->id)->first();

        if ($membership !== null) {
            $checkout->expireStale($membership);
        }
        $fee = $fees->for($member);

        $attempts = $membership === null ? collect() : PaymentAttempt::query()
            ->whereIn('payment_intent_id', $membership->paymentIntents()->select('id'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $status = $membership === null ? MembershipStatus::PendingPayment : $membership->status;

        $quote = null;

        if ($status !== MembershipStatus::Active) {
            try {
                $breakdown = $feeCalculator->forNet($settings->string('payment.inbound_provider') ?? '', $fee['amount']);
                $quote = [
                    'net_amount' => $breakdown->net,
                    'fee_amount' => $breakdown->fee,
                    'total_amount' => $breakdown->total,
                    'currency' => MembershipFee::CURRENCY,
                    'fees_borne_by' => $breakdown->fee > 0 ? 'member' : 'cect',
                ];
            } catch (ApiException) {
                // Fees not configured yet: the quote is simply unavailable, starting a payment is refused.
            }
        }

        return response()->json(['data' => [
            'status' => $status->value,
            'account_status' => $member->status->value,
            'fee_due' => $status === MembershipStatus::Active ? 0 : $fee['amount'],
            'fee_currency' => MembershipFee::CURRENCY,
            'payment_quote' => $quote,
            'membership' => $membership === null ? null : new MembershipResource($membership),
            'payment_attempts' => PaymentAttemptResource::collection($attempts),
        ]]);
    }

    /**
     * Start a payment of the membership fee.
     *
     * Choose a method (T-Money, Flooz, cash, bank transfer). For a provider method the response carries the hosted
     * checkout link; for a manual one (cash, declared deposit), the instructions to pay, then the payment is declared
     * and validated by the CECT. An attempt already awaiting payment with the same method is returned as is (200).
     * The account becomes ACTIVE only when the provider confirms the payment server-side.
     */
    public function startPayment(Request $request, StartMembershipPayment $start): JsonResponse
    {
        $validated = $request->validate(['method' => ['nullable', 'string', 'max:30']]);

        ['attempt' => $attempt, 'created' => $created] = $start->handle($this->member($request), $validated['method'] ?? null);

        return (new PaymentAttemptResource($attempt))->response()->setStatusCode($created ? 201 : 200);
    }

    /**
     * Status of one of your payment attempts.
     */
    public function showAttempt(Request $request, string $attempt): PaymentAttemptResource
    {
        return new PaymentAttemptResource($this->ownedAttempt($request, $attempt));
    }

    /**
     * Ask the platform to re-check an attempt with the provider (useful when the confirmation is late).
     */
    public function refreshAttempt(Request $request, string $attempt, RefreshPaymentAttempt $refresh): PaymentAttemptResource
    {
        return new PaymentAttemptResource($refresh->handle($this->ownedAttempt($request, $attempt)));
    }

    /**
     * Declare a payment made outside the provider (cash handed over, Mobile Money deposit, transfer), with its reference.
     */
    public function declarePayment(Request $request, string $attempt, ManualPaymentDesk $desk): PaymentAttemptResource
    {
        $validated = $request->validate(['reference' => ['nullable', 'string', 'max:120']]);

        return new PaymentAttemptResource($desk->declare($this->member($request), $this->ownedAttempt($request, $attempt), $validated['reference'] ?? null));
    }

    /**
     * DEMO_GATEWAY_BYPASS: activate the account without payment. Not available in production.
     */
    public function demoActivate(Request $request, DemoActivateMembership $demo): JsonResponse
    {
        $member = $this->member($request);
        $membership = $demo->handle($member);

        return response()->json(['data' => [
            'membership' => new MembershipResource($membership),
            'account_status' => UserStatus::Active->value,
        ]]);
    }

    /**
     * DEMO_GATEWAY_BYPASS: approve a payment attempt made with the fake provider, as its webhook would.
     * Not available in production.
     */
    public function demoSettle(Request $request, string $attempt, RefreshPaymentAttempt $refresh, FakePaymentProvider $fake, SettingsService $settings): PaymentAttemptResource
    {
        $paymentAttempt = $this->ownedAttempt($request, $attempt);

        if (! app()->environment('testing') || ! $settings->boolean('platform.demo_gateway_bypass')) {
            throw PaymentException::demoUnavailable();
        }

        if ($paymentAttempt->provider !== FakePaymentProvider::NAME || $paymentAttempt->provider_transaction_id === null) {
            throw PaymentException::notPayable();
        }

        $fake->simulate($paymentAttempt->provider_transaction_id, ProviderPaymentStatus::Approved);

        return new PaymentAttemptResource($refresh->handle($paymentAttempt));
    }

    private function ownedAttempt(Request $request, string $id): PaymentAttempt
    {
        $member = $this->member($request);

        return PaymentAttempt::query()
            ->whereKey($id)
            ->whereHas('intent', fn ($query) => $query->where('user_id', $member->id))
            ->firstOrFail();
    }

    private function member(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isMember()) {
            throw PaymentException::notPayable();
        }

        return $user;
    }
}

[executed on device: ip-172-31-20-3 (266007aa-ed81-449a-969b-4ac5ea49fe76)]