<?php

namespace App\Domain\Payment\Http\Resources;

use App\Domain\Payment\CollectionRouter;
use App\Domain\Payment\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Member-facing view of an attempt: `amount` is what the member is charged (net amount + transaction fees).
 * A provider attempt carries its checkout link while it can be completed; a manual one carries the instructions
 * to pay and whether it has been declared and validated.
 *
 * @mixin PaymentAttempt
 */
class PaymentAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $manual = $this->provider === CollectionRouter::MANUAL_PROVIDER;

        return [
            'id' => $this->id,
            'reference' => $this->humanReference(),
            'provider' => $this->provider,
            'method' => $this->method,
            'mode' => $manual ? 'manual' : 'psp',
            'status' => $this->status->value,
            'net_amount' => $this->amount - $this->fee_amount,
            'fee_amount' => $this->fee_amount,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'checkout_url' => ! $manual && $this->status->isInFlight() ? $this->checkout_url : null,
            'instructions' => $manual && $this->status->isInFlight() && $this->method !== null
                ? app(CollectionRouter::class)->instructions($this->method, $this->amount, $this->humanReference())
                : null,
            'declared_reference' => $this->declared_reference,
            'declared_at' => $this->declared_at?->toIso8601ZuluString(),
            'validated_at' => $this->validated_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'settled_at' => $this->settled_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Short reference the member quotes when paying in cash or by transfer.
     */
    private function humanReference(): string
    {
        return 'PAY-'.strtoupper(substr($this->id, -8));
    }
}
