<?php

namespace App\Domain\Payment\Jobs;

use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Payment\Actions\RefreshPaymentAttempt;
use App\Domain\Payment\Enums\WebhookEventStatus;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Payment\Models\ProviderWebhookEvent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes a stored webhook. The payload only identifies the transaction: the outcome is always
 * re-read from the provider, so replayed, reordered or forged-then-signed events cannot settle a payment.
 */
class ProcessProviderWebhookEvent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $eventId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(RefreshPaymentAttempt $refresh, SecurityEventLogger $securityLogger): void
    {
        $event = ProviderWebhookEvent::query()->find($this->eventId);

        if ($event === null || in_array($event->status, [WebhookEventStatus::Processed, WebhookEventStatus::Ignored], true)) {
            return;
        }

        $attempt = $event->provider_transaction_id === null ? null : PaymentAttempt::query()
            ->where('provider', $event->provider)
            ->where('provider_transaction_id', $event->provider_transaction_id)
            ->first();

        if ($attempt === null) {
            $event->forceFill(['status' => WebhookEventStatus::Ignored, 'processed_at' => now()])->save();

            if ($event->provider_transaction_id !== null) {
                $securityLogger->record('webhook.unknown_transaction', SecuritySeverity::Warning, metadata: [
                    'provider' => $event->provider,
                    'provider_transaction_id' => $event->provider_transaction_id,
                ]);
            }

            return;
        }

        $refresh->handle($attempt);

        $event->forceFill(['status' => WebhookEventStatus::Processed, 'processed_at' => now(), 'error' => null])->save();
    }

    public function failed(Throwable $exception): void
    {
        ProviderWebhookEvent::query()->whereKey($this->eventId)->update([
            'status' => WebhookEventStatus::Failed->value,
            'error' => mb_substr($exception->getMessage(), 0, 500),
        ]);
    }
}
