<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Membership\Actions\ActivateMembership;
use App\Domain\Membership\Enums\ActivationMode;
use App\Domain\Membership\Models\Membership;
use App\Domain\Payment\Data\ProviderTransaction;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentIntentStatus;
use App\Domain\Payment\Enums\PaymentPurpose;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Subscription\Actions\ActivateUserPack;
use App\Domain\Subscription\Actions\CreditPackTopup;
use App\Domain\Subscription\Models\PackTopup;
use App\Domain\Subscription\Models\UserPack;
use Illuminate\Support\Facades\DB;

/**
 * Applies the state of a transaction, re-read from the provider, to an attempt and its intent.
 *
 * Idempotent and monotone: locks the intent, never revisits a final attempt, never settles an intent twice.
 * Anything suspicious (amount mismatch, double payment, payment on a canceled intent) is flagged for review
 * instead of being silently accepted.
 */
class SettlePayment
{
    public function __construct(
        private readonly ActivateMembership $activateMembership,
        private readonly ActivateUserPack $activateUserPack,
        private readonly CreditPackTopup $creditPackTopup,
        private readonly SecurityEventLogger $securityLogger,
    ) {}

    public function handle(PaymentAttempt $attempt, ProviderTransaction $transaction): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $transaction): PaymentAttempt {
            $intent = PaymentIntent::query()->lockForUpdate()->findOrFail($attempt->payment_intent_id);
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($attempt->status->isFinal()) {
                return $attempt;
            }

            $attempt->last_checked_at = now();

            match ($transaction->status) {
                ProviderPaymentStatus::Declined => $this->close($attempt, PaymentAttemptStatus::Declined),
                ProviderPaymentStatus::Canceled => $this->close($attempt, PaymentAttemptStatus::Canceled),
                ProviderPaymentStatus::Expired => $this->close($attempt, PaymentAttemptStatus::Expired),
                ProviderPaymentStatus::Approved => $this->approve($attempt, $intent, $transaction),
                ProviderPaymentStatus::Pending, ProviderPaymentStatus::Refunded, ProviderPaymentStatus::Unknown => $attempt->save(),
            };

            return $attempt;
        });
    }

    private function close(PaymentAttempt $attempt, PaymentAttemptStatus $status): void
    {
        $attempt->forceFill(['status' => $status, 'settled_at' => now()])->save();
    }

    private function approve(PaymentAttempt $attempt, PaymentIntent $intent, ProviderTransaction $transaction): void
    {
        $echoedAttemptId = $transaction->metadata['attempt_id'] ?? null;

        $matches = $transaction->amount === $intent->total_amount
            && $transaction->currency === $intent->currency
            && ($echoedAttemptId === null || $echoedAttemptId === $attempt->id);

        if (! $matches) {
            $this->flag($attempt, PaymentAttemptStatus::Mismatch, 'amount_or_reference_mismatch', $intent, [
                'expected_amount' => $intent->total_amount,
                'received_amount' => $transaction->amount,
                'expected_currency' => $intent->currency,
                'received_currency' => $transaction->currency,
            ]);

            return;
        }

        if ($intent->status === PaymentIntentStatus::Open) {
            $attempt->forceFill(['status' => PaymentAttemptStatus::Approved, 'settled_at' => now()])->save();
            $intent->forceFill(['status' => PaymentIntentStatus::Succeeded, 'succeeded_at' => now()])->save();

            $this->onPaid($intent);

            return;
        }

        $this->flag(
            $attempt,
            PaymentAttemptStatus::Approved,
            $intent->status === PaymentIntentStatus::Succeeded ? 'duplicate_payment' : 'payment_on_canceled_intent',
            $intent,
        );
    }

    private function onPaid(PaymentIntent $intent): void
    {
        match ($intent->purpose) {
            PaymentPurpose::Membership => $this->activateMembership->handle(
                Membership::query()->lockForUpdate()->findOrFail($intent->payable_id),
                app()->environment('staging')
                    && $intent->attempts()->where('provider', \App\Domain\Payment\Providers\Fake\FakePaymentProvider::NAME)
                        ->where('status', PaymentAttemptStatus::Approved)->exists()
                    ? ActivationMode::SimulatedPayment : ActivationMode::Payment,
                $intent,
            ),
            PaymentPurpose::PackSubscription => $this->activateUserPack->handle(
                UserPack::query()->findOrFail($intent->payable_id),
                $intent,
            ),
            PaymentPurpose::PackTopup => $this->creditPackTopup->handle(
                PackTopup::query()->findOrFail($intent->payable_id),
                $intent,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function flag(PaymentAttempt $attempt, PaymentAttemptStatus $status, string $reason, PaymentIntent $intent, array $details = []): void
    {
        $attempt->forceFill([
            'status' => $status,
            'requires_review' => true,
            'review_reason' => $reason,
            'settled_at' => now(),
        ])->save();

        $this->securityLogger->record("payment.{$reason}", SecuritySeverity::Critical, $intent->user_id, metadata: [
            'payment_attempt_id' => $attempt->id,
            'payment_intent_id' => $intent->id,
            'provider' => $attempt->provider,
            'provider_transaction_id' => $attempt->provider_transaction_id,
            ...$details,
        ]);
    }
}
