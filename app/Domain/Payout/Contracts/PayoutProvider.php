<?php

namespace App\Domain\Payout\Contracts;

use App\Domain\Payout\Data\PayoutRequest;
use App\Domain\Payout\Data\PayoutResult;
use App\Domain\Payout\Exceptions\PayoutProviderException;

/**
 * Outbound transfers (Mobile Money). The domain never depends on a vendor SDK.
 */
interface PayoutProvider
{
    public function name(): string;

    /**
     * Creates and starts a transfer.
     *
     * @throws PayoutProviderException when the outcome is unknown (network, timeout) or the request was refused
     */
    public function send(PayoutRequest $request): PayoutResult;

    /**
     * @throws PayoutProviderException
     */
    public function fetch(string $providerReference): PayoutResult;

    /**
     * Finds a transfer by our own reference, to recover from a lost response without sending twice.
     *
     * @throws PayoutProviderException
     */
    public function findByMerchantReference(string $merchantReference): ?PayoutResult;

    /**
     * Starts a transfer that was created but not started.
     *
     * @throws PayoutProviderException
     */
    public function start(string $providerReference): PayoutResult;
}
