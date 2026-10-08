<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Payment\Exceptions\PaymentProviderException;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\PaymentProviderManager;

/**
 * Re-reads the transaction at the provider (server to server) and settles the attempt accordingly.
 * Used by webhook processing and by on-demand reconciliation when a webhook is late or lost.
 */
class RefreshPaymentAttempt
{
    public function __construct(
        private readonly PaymentProviderManager $providers,
        private readonly SettlePayment $settle,
    ) {}

    public function handle(PaymentAttempt $attempt): PaymentAttempt
    {
        if ($attempt->provider_transaction_id === null || $attempt->status === PaymentAttemptStatus::Created || $attempt->status->isFinal()) {
            return $attempt;
        }

        try {
            $transaction = $this->providers->provider($attempt->provider)->fetchTransaction($attempt->provider_transaction_id);
        } catch (PaymentProviderException $exception) {
            report($exception);

            throw PaymentException::providerUnavailable();
        }

        return $this->settle->handle($attempt, $transaction);
    }
}
