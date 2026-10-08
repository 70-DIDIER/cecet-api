<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\Data\CheckoutRequest;
use App\Domain\Payment\Data\CheckoutSession;
use App\Domain\Payment\Data\ProviderTransaction;
use App\Domain\Payment\Data\WebhookEvent;
use App\Domain\Payment\Exceptions\InvalidWebhookSignature;
use App\Domain\Payment\Exceptions\PaymentProviderException;

/**
 * Inbound payment provider (collection). The business domain depends on this interface, never on a vendor SDK.
 */
interface PaymentProvider
{
    public function name(): string;

    /**
     * Opens a hosted checkout for the customer.
     *
     * @throws PaymentProviderException
     */
    public function createCheckout(CheckoutRequest $request): CheckoutSession;

    /**
     * Re-reads a transaction from the provider.
     *
     * @throws PaymentProviderException
     */
    public function fetchTransaction(string $providerTransactionId): ProviderTransaction;

    /**
     * Verifies the authenticity of a webhook and extracts the event.
     *
     * @param  array<string, string>  $headers  lowercase header names
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(string $payload, array $headers, int $toleranceSeconds): WebhookEvent;
}
