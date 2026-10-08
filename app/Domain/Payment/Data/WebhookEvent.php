<?php

namespace App\Domain\Payment\Data;

/**
 * A webhook whose signature has been verified.
 */
final readonly class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $providerEventId,
        public string $name,
        public ?string $providerTransactionId,
        public array $payload,
    ) {}
}
