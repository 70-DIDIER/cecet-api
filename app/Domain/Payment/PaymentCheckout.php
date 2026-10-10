<?php

namespace App\Domain\Payment;

use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Payment\Data\CheckoutRequest;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentIntentStatus;
use App\Domain\Payment\Enums\PaymentPurpose;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Payment\Exceptions\PaymentProviderException;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Shared mechanics to collect a payment for any payable object (membership, pack subscription, ...).
 *
 * prepare() runs inside the caller's transaction (and after the caller locked what it needs):
 * it abandons stale attempts, reuses a checkout still awaiting payment, replaces an unpaid intent whose amount
 * changed, and records a new attempt. dispatch() then opens the hosted checkout at the provider, outside any
 * database transaction.
 */
class PaymentCheckout
{
    /**
     * An attempt stuck in CREATED (provider call never finished) is abandoned after this delay.
     */
    private const CREATED_TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PaymentFeeCalculator $feeCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $intentMetadata
     * @param  Closure(PaymentIntent, PaymentIntent|null): void|null  $onIntentOpened  called with the new intent and the unpaid intent it replaced
     * @return array{attempt: PaymentAttempt, reused: bool}
     */
    public function prepare(
        User $payer,
        Model $payable,
        PaymentPurpose $purpose,
        int $netAmount,
        string $providerName,
        array $intentMetadata = [],
        ?Closure $onIntentOpened = null,
        ?string $method = null,
    ): array {
        $breakdown = $this->feeCalculator->forNet($providerName, $netAmount);
        $manual = $providerName === CollectionRouter::MANUAL_PROVIDER;

        $this->expireStale($payable);

        $inFlight = PaymentAttempt::query()
            ->whereIn('payment_intent_id', $this->intentsOf($payable)->select('id'))
            ->whereIn('status', [PaymentAttemptStatus::Created, PaymentAttemptStatus::Pending])
            ->latest('id')
            ->first();

        // The member changed their mind (e.g. cash instead of T-Money): the previous attempt is abandoned locally.
        if ($inFlight !== null && $inFlight->status === PaymentAttemptStatus::Pending && $method !== null && $inFlight->method !== $method) {
            $inFlight->forceFill($inFlight->provider === CollectionRouter::MANUAL_PROVIDER
                ? ['status' => PaymentAttemptStatus::Canceled, 'settled_at' => now()]
                : ['status' => PaymentAttemptStatus::Expired])->save();
            $inFlight = null;
        }

        if ($inFlight !== null) {
            return $inFlight->status === PaymentAttemptStatus::Pending
                ? ['attempt' => $inFlight, 'reused' => true]
                : throw PaymentException::attemptInProgress();
        }

        $open = $this->intentsOf($payable)->where('status', PaymentIntentStatus::Open)->first();

        if ($open instanceof PaymentIntent && $open->amount === $breakdown->net && $open->total_amount === $breakdown->total) {
            $intent = $open;
        } else {
            $open?->forceFill(['status' => PaymentIntentStatus::Canceled, 'canceled_at' => now()])->save();

            $intent = PaymentIntent::query()->create([
                'user_id' => $payer->id,
                'purpose' => $purpose,
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => (string) $payable->getKey(),
                'amount' => $breakdown->net,
                'fee_amount' => $breakdown->fee,
                'total_amount' => $breakdown->total,
                'currency' => 'XOF',
                'status' => PaymentIntentStatus::Open,
                'metadata' => [...$intentMetadata, 'provider' => $providerName, 'fee_rule' => $breakdown->rule],
            ]);

            $onIntentOpened?->__invoke($intent, $open);
        }

        $attempt = $intent->attempts()->create([
            'provider' => $providerName,
            'method' => $method,
            // A manual payment awaits the member's declaration and the staff's validation: nothing to open.
            'status' => $manual ? PaymentAttemptStatus::Pending : PaymentAttemptStatus::Created,
            'amount' => $intent->total_amount,
            'fee_amount' => $intent->fee_amount,
            'currency' => $intent->currency,
        ]);

        return ['attempt' => $attempt, 'reused' => false];
    }

    /**
     * Opens the hosted checkout at the provider for a freshly prepared attempt.
     *
     * @param  array<string, string>  $metadata
     */
    public function dispatch(PaymentAttempt $attempt, PaymentProvider $provider, User $payer, PaymentPurpose $purpose, string $description, array $metadata = [], ?string $customerPhone = null): PaymentAttempt
    {
        try {
            $session = $provider->createCheckout(new CheckoutRequest(
                attemptId: $attempt->id,
                amount: $attempt->amount,
                currency: $attempt->currency,
                description: $description,
                customerEmail: $payer->email,
                customerFirstName: $payer->first_name,
                customerLastName: $payer->last_name,
                customerPhone: $customerPhone ?? $payer->phone,
                returnUrl: config('payments.return_url'),
                metadata: [
                    ...$metadata,
                    'attempt_id' => $attempt->id,
                    'intent_id' => $attempt->payment_intent_id,
                    'purpose' => $purpose->value,
                    'net_amount' => (string) ($attempt->amount - $attempt->fee_amount),
                    'fee_amount' => (string) $attempt->fee_amount,
                ],
            ));
        } catch (PaymentProviderException $exception) {
            report($exception);
            $attempt->forceFill(['status' => PaymentAttemptStatus::Failed, 'failure_reason' => 'provider_error', 'settled_at' => now()])->save();

            throw PaymentException::providerUnavailable();
        }

        $attempt->forceFill([
            'status' => PaymentAttemptStatus::Pending,
            'provider_transaction_id' => $session->providerTransactionId,
            'provider_reference' => $session->providerReference,
            'checkout_url' => $session->checkoutUrl,
        ])->save();

        return $attempt;
    }

    /**
     * @return Builder<PaymentIntent>
     */
    private function intentsOf(Model $payable): Builder
    {
        return PaymentIntent::query()
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', (string) $payable->getKey());
    }

    /**
     * Abandons the attempts of a payable that outlived their delay. An EXPIRED attempt is a local decision only:
     * the provider may still approve it later, and that payment is then settled or flagged for review.
     */
    public function expireStale(Model $payable): void
    {
        /** @var Collection<int, PaymentAttempt> $attempts */
        $attempts = PaymentAttempt::query()
            ->whereIn('payment_intent_id', $this->intentsOf($payable)->select('id'))
            ->whereIn('status', [PaymentAttemptStatus::Created, PaymentAttemptStatus::Pending])
            ->get();

        $pendingExpiry = now()->subMinutes($this->settings->integer('payment.attempt_expiry_minutes'));
        $manualExpiry = now()->subDays($this->settings->integer('payment.manual_declaration_ttl_days'));
        $createdExpiry = now()->subSeconds(self::CREATED_TIMEOUT_SECONDS);

        foreach ($attempts as $attempt) {
            if ($attempt->provider === CollectionRouter::MANUAL_PROVIDER) {
                if ($attempt->declared_at === null && $attempt->created_at->lessThan($manualExpiry)) {
                    $attempt->forceFill(['status' => PaymentAttemptStatus::Expired])->save();
                }

                continue;
            }

            if ($attempt->status === PaymentAttemptStatus::Created && $attempt->created_at->lessThan($createdExpiry)) {
                $attempt->forceFill(['status' => PaymentAttemptStatus::Failed, 'failure_reason' => 'abandoned_before_checkout', 'settled_at' => now()])->save();
            } elseif ($attempt->status === PaymentAttemptStatus::Pending && $attempt->created_at->lessThan($pendingExpiry)) {
                $attempt->forceFill(['status' => PaymentAttemptStatus::Expired])->save();
            }
        }
    }
}
