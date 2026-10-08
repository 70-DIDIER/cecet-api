<?php

namespace App\Domain\Payment\Data;

/**
 * What the platform asks a provider to collect. The amount is decided by the server.
 */
final readonly class CheckoutRequest
{
    /**
     * @param  array<string, string>  $metadata  echoed back by providers that support custom metadata
     */
    public function __construct(
        public string $attemptId,
        public int $amount,
        public string $currency,
        public string $description,
        public string $customerEmail,
        public string $customerFirstName,
        public string $customerLastName,
        public ?string $customerPhone,
        public ?string $returnUrl,
        public array $metadata = [],
    ) {}
}
