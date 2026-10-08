<?php

namespace App\Domain\Payment\Providers\Fake;

use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Payment\Data\CheckoutRequest;
use App\Domain\Payment\Data\CheckoutSession;
use App\Domain\Payment\Data\ProviderTransaction;
use App\Domain\Payment\Data\WebhookEvent;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Exceptions\InvalidWebhookSignature;
use App\Domain\Payment\Exceptions\PaymentProviderException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * In-memory provider for development, demos and tests. It can never be resolved in production.
 *
 * Transactions live in the cache. Webhooks are signed with "X-Fake-Signature" = HMAC-SHA256(body, secret).
 */
class FakePaymentProvider implements PaymentProvider
{
    public const NAME = 'fake';

    public const SIGNATURE_HEADER = 'x-fake-signature';

    public function name(): string
    {
        return self::NAME;
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $id = 'fake_'.Str::lower((string) Str::ulid());

        Cache::put($this->key($id), [
            'status' => ProviderPaymentStatus::Pending->value,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'metadata' => $request->metadata,
        ], now()->addDays(2));

        return new CheckoutSession($id, 'FAKE-'.Str::upper(Str::random(8)), rtrim((string) config('app.url'), '/').'/fake-checkout/'.$id);
    }

    public function fetchTransaction(string $providerTransactionId): ProviderTransaction
    {
        $state = Cache::get($this->key($providerTransactionId));

        if (! is_array($state)) {
            throw new PaymentProviderException('Unknown fake transaction.');
        }

        return new ProviderTransaction(
            providerTransactionId: $providerTransactionId,
            status: ProviderPaymentStatus::from((string) $state['status']),
            amount: (int) $state['amount'],
            currency: (string) $state['currency'],
            metadata: is_array($state['metadata'] ?? null) ? $state['metadata'] : [],
        );
    }

    public function parseWebhook(string $payload, array $headers, int $toleranceSeconds): WebhookEvent
    {
        $expected = hash_hmac('sha256', $payload, $this->secret());

        if (! hash_equals($expected, $headers[self::SIGNATURE_HEADER] ?? '')) {
            throw new InvalidWebhookSignature('Signature mismatch.');
        }

        $data = json_decode($payload, true);

        if (! is_array($data) || ! is_string($data['name'] ?? null) || ! is_string($data['id'] ?? null)) {
            throw new InvalidWebhookSignature('Unreadable event payload.');
        }

        $transactionId = $data['entity']['id'] ?? null;

        return new WebhookEvent($data['id'], $data['name'], is_string($transactionId) ? $transactionId : null, $data);
    }

    /**
     * Moves a fake transaction to the given status and returns the signed webhook describing it.
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public function simulate(string $providerTransactionId, ProviderPaymentStatus $status, ?int $amount = null, ?string $eventId = null): array
    {
        $state = Cache::get($this->key($providerTransactionId));

        if (! is_array($state)) {
            throw new PaymentProviderException('Unknown fake transaction.');
        }

        $state['status'] = $status->value;
        $state['amount'] = $amount ?? $state['amount'];
        Cache::put($this->key($providerTransactionId), $state, now()->addDays(2));

        return $this->signedWebhook("transaction.{$status->value}", $providerTransactionId, $eventId);
    }

    /**
     * Builds a signed webhook without touching the transaction state.
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public function signedWebhook(string $name, string $providerTransactionId, ?string $eventId = null): array
    {
        $body = (string) json_encode([
            'id' => $eventId ?? 'evt_'.Str::lower((string) Str::ulid()),
            'name' => $name,
            'entity' => ['id' => $providerTransactionId],
        ]);

        return ['body' => $body, 'headers' => [self::SIGNATURE_HEADER => hash_hmac('sha256', $body, $this->secret())]];
    }

    private function key(string $transactionId): string
    {
        return "fake_payment:{$transactionId}";
    }

    private function secret(): string
    {
        return (string) config('services.fake_payment.webhook_secret');
    }
}
