<?php

namespace App\Domain\Payment\Data;

use App\Domain\Payment\Enums\ProviderPaymentStatus;

/**
 * Transaction state as re-read from the provider, server to server. This is the only source of truth
 * for settling a payment: webhook payloads only tell us which transaction to re-read.
 */
final readonly class ProviderTransaction
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $providerTransactionId,
        public ProviderPaymentStatus $status,
        public int $amount,
        public string $currency,
        public ?string $reference = null,
        public array $metadata = [],
    ) {}
}
