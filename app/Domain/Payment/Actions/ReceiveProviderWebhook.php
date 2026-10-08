<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Payment\Enums\WebhookEventStatus;
use App\Domain\Payment\Exceptions\InvalidWebhookSignature;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Payment\Jobs\ProcessProviderWebhookEvent;
use App\Domain\Payment\Models\ProviderWebhookEvent;
use App\Domain\Payment\PaymentProviderManager;
use App\Domain\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Authenticates a provider webhook, stores it once (deduplicated by provider event id) and queues its processing,
 * so that the provider receives its 2xx answer quickly.
 */
class ReceiveProviderWebhook
{
    public function __construct(
        private readonly PaymentProviderManager $providers,
        private readonly SettingsService $settings,
        private readonly SecurityEventLogger $securityLogger,
    ) {}

    /**
     * @return bool true when the event is new, false when it was already received
     */
    public function handle(string $providerName, Request $request): bool
    {
        $provider = $this->providers->provider($providerName);
        $payload = $request->getContent();

        try {
            $event = $provider->parseWebhook($payload, $this->lowercaseHeaders($request), $this->settings->integer('payment.webhook_tolerance_seconds'));
        } catch (InvalidWebhookSignature $exception) {
            $this->securityLogger->record('webhook.invalid_signature', SecuritySeverity::Warning, metadata: [
                'provider' => $providerName,
                'reason' => $exception->getMessage(),
                'payload_sha256' => hash('sha256', $payload),
            ]);

            throw PaymentException::invalidWebhook();
        }

        $stored = ProviderWebhookEvent::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'provider' => $providerName,
            'provider_event_id' => $event->providerEventId,
            'event_name' => $event->name,
            'provider_transaction_id' => $event->providerTransactionId,
            'payload' => json_encode($event->payload),
            'status' => WebhookEventStatus::Received->value,
            'received_at' => now()->format('Y-m-d H:i:s.uP'),
            'ip_address' => $request->ip(),
        ]) === 1;

        $record = ProviderWebhookEvent::query()
            ->where('provider', $providerName)
            ->where('provider_event_id', $event->providerEventId)
            ->firstOrFail();

        if (! $stored) {
            $this->securityLogger->record('webhook.duplicate', metadata: ['provider' => $providerName, 'provider_event_id' => $event->providerEventId]);

            // A provider retry is the chance to recover an event whose processing failed or never started.
            if (in_array($record->status, [WebhookEventStatus::Received, WebhookEventStatus::Failed], true)) {
                ProcessProviderWebhookEvent::dispatch($record->id);
            }

            return false;
        }

        ProcessProviderWebhookEvent::dispatch($record->id);

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function lowercaseHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return $headers;
    }
}
