<?php

namespace App\Domain\Payment\Data;

final readonly class CheckoutSession
{
    public function __construct(
        public string $providerTransactionId,
        public ?string $providerReference,
        public string $checkoutUrl,
    ) {}
}
