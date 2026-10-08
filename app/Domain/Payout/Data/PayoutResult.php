<?php

namespace App\Domain\Payout\Data;

use App\Domain\Payout\Enums\ProviderPayoutStatus;

final readonly class PayoutResult
{
    /**
     * @param  bool  $needsStart  created at the provider but not yet started (FedaPay two-step flow)
     */
    public function __construct(
        public string $providerReference,
        public ProviderPayoutStatus $status,
        public ?string $failureCode = null,
        public bool $needsStart = false,
    ) {}
}
