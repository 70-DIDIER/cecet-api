<?php

namespace App\Domain\Payout\Data;

/**
 * One transfer to send. The merchant reference is our idempotency handle at the provider.
 */
final readonly class PayoutRequest
{
    public function __construct(
        public string $merchantReference,
        public int $amount,
        public string $currency,
        public string $phone,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $method = null,
        public string $country = 'TG',
    ) {}
}
