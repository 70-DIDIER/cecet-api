<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Notification\Notifier;
use App\Domain\Payment\CollectionRouter;
use App\Domain\Payment\Data\ProviderTransaction;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Enums\PaymentPurpose;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Payments collected outside the provider (cash, Mobile Money deposits, bank transfers), as on the former site:
 * the member declares the payment, a staff member checks the money really arrived and validates it.
 *
 * Validation goes through the same settlement as a provider confirmation: the amount must match the total owed, and
 * the membership or pack is activated exactly as after a webhook.
 */
class ManualPaymentDesk
{
    public function __construct(
        private readonly SettlePayment $settle,
        private readonly AuditLogger $auditLogger,
        private readonly Notifier $notifier,
    ) {}

    /**
     * The member states that the payment was made, with the operator or bank reference when there is one.
     */
    public function declare(User $member, PaymentAttempt $attempt, ?string $reference): PaymentAttempt
    {
        return DB::transaction(function () use ($member, $attempt, $reference): PaymentAttempt {
            $attempt = $this->lockAwaiting($attempt);

            $attempt->forceFill(['declared_reference' => $reference, 'declared_at' => now()])->save();
            $this->auditLogger->record('payments.manual_declared', $attempt, after: ['method' => $attempt->method, 'declared_reference' => $reference], actor: $member);

            return $attempt;
        });
    }

    public function approve(User $validator, PaymentAttempt $attempt, int $receivedAmount, string $reference, ?string $comment): PaymentAttempt
    {
        $attempt = DB::transaction(function () use ($validator, $attempt, $receivedAmount, $reference, $comment): PaymentAttempt {
            $attempt = $this->lockAwaiting($attempt);

            if ($receivedAmount !== $attempt->amount) {
                throw PaymentException::receivedAmountMismatch($attempt->amount);
            }

            $attempt->forceFill([
                'provider_reference' => $reference,
                'validated_by' => $validator->id,
                'validated_at' => now(),
                'validation_comment' => $comment,
            ])->save();

            $this->auditLogger->record('payments.manual_validated', $attempt, after: [
                'method' => $attempt->method,
                'amount' => $receivedAmount,
                'reference' => $reference,
                'declared_reference' => $attempt->declared_reference,
            ], reason: $comment, actor: $validator);

            return $attempt;
        });

        return $this->settle->handle($attempt, new ProviderTransaction(
            providerTransactionId: $attempt->id,
            status: ProviderPaymentStatus::Approved,
            amount: $receivedAmount,
            currency: $attempt->currency,
            reference: $reference,
            metadata: ['attempt_id' => $attempt->id],
        ));
    }

    public function reject(User $validator, PaymentAttempt $attempt, string $reason): PaymentAttempt
    {
        return DB::transaction(function () use ($validator, $attempt, $reason): PaymentAttempt {
            $attempt = $this->lockAwaiting($attempt);

            $attempt->forceFill([
                'status' => PaymentAttemptStatus::Declined,
                'settled_at' => now(),
                'validated_by' => $validator->id,
                'validated_at' => now(),
                'validation_comment' => $reason,
            ])->save();

            $this->auditLogger->record('payments.manual_rejected', $attempt, after: ['method' => $attempt->method, 'declared_reference' => $attempt->declared_reference], reason: $reason, actor: $validator);

            $intent = $attempt->intent()->with('user')->firstOrFail();
            $this->notifier->notify($intent->user, 'payment.rejected', [
                'reference' => 'PAY-'.strtoupper(substr($attempt->id, -8)),
                'label' => match ($intent->purpose) {
                    PaymentPurpose::Membership => 'adhésion',
                    PaymentPurpose::PackSubscription => 'souscription de pack',
                    PaymentPurpose::PackTopup => 'rechargement Perso',
                },
                'reason' => $reason,
            ]);

            return $attempt;
        });
    }

    private function lockAwaiting(PaymentAttempt $attempt): PaymentAttempt
    {
        $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

        if ($attempt->provider !== CollectionRouter::MANUAL_PROVIDER) {
            throw PaymentException::notManual();
        }

        if ($attempt->status !== PaymentAttemptStatus::Pending) {
            throw PaymentException::notAwaitingValidation($attempt->status->value);
        }

        return $attempt;
    }
}
