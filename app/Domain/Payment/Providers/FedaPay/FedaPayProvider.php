<?php

namespace App\Domain\Payment\Providers\FedaPay;

use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Payment\Data\CheckoutRequest;
use App\Domain\Payment\Data\CheckoutSession;
use App\Domain\Payment\Data\ProviderTransaction;
use App\Domain\Payment\Data\WebhookEvent;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Exceptions\InvalidWebhookSignature;
use App\Domain\Payment\Exceptions\PaymentProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * FedaPay collection adapter (REST API v1).
 *
 * Webhook signature (as implemented by the official SDK): header "X-FEDAPAY-SIGNATURE" = "t=<unix>,s=<hex>",
 * where s = HMAC-SHA256("<t>.<raw body>", endpoint secret). Several "s" values may be present during rotation.
 */
class FedaPayProvider implements PaymentProvider
{
    public const NAME = 'fedapay';

    public const SIGNATURE_HEADER = 'x-fedapay-signature';

    private const SANDBOX_URL = 'https://sandbox-api.fedapay.com/v1';

    private const LIVE_URL = 'https://api.fedapay.com/v1';

    public function name(): string
    {
        return self::NAME;
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $transaction = $this->unwrap($this->send(fn (PendingRequest $http): Response => $http->post('/transactions', $this->transactionPayload($request))));

        $transactionId = $this->identifier($transaction['id'] ?? null);
        $token = $this->unwrap($this->send(fn (PendingRequest $http): Response => $http->post("/transactions/{$transactionId}/token")), 'token');
        $url = $token['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new PaymentProviderException('FedaPay did not return a payment link.');
        }

        return new CheckoutSession(
            providerTransactionId: $transactionId,
            providerReference: isset($transaction['reference']) && is_scalar($transaction['reference']) ? (string) $transaction['reference'] : null,
            checkoutUrl: $url,
        );
    }

    public function fetchTransaction(string $providerTransactionId): ProviderTransaction
    {
        $id = rawurlencode($providerTransactionId);
        $transaction = $this->unwrap($this->send(fn (PendingRequest $http): Response => $http->retry(2, 250, throw: false)->get("/transactions/{$id}")));

        $amount = $transaction['amount'] ?? null;
        $currency = $this->transactionCurrency($transaction);

        if (! is_numeric($amount) || $currency === null) {
            throw new PaymentProviderException('FedaPay returned an incomplete transaction.');
        }

        $metadata = $transaction['custom_metadata'] ?? [];

        return new ProviderTransaction(
            providerTransactionId: $this->identifier($transaction['id'] ?? $providerTransactionId),
            status: $this->mapStatus($transaction['status'] ?? null),
            amount: (int) round((float) $amount),
            currency: strtoupper($currency),
            reference: isset($transaction['reference']) && is_scalar($transaction['reference']) ? (string) $transaction['reference'] : null,
            metadata: is_array($metadata) ? $metadata : [],
        );
    }

    public function parseWebhook(string $payload, array $headers, int $toleranceSeconds): WebhookEvent
    {
        $header = $headers[self::SIGNATURE_HEADER] ?? '';
        $secret = (string) config('services.fedapay.webhook_secret');

        if ($secret === '') {
            throw new InvalidWebhookSignature('FedaPay webhook secret is not configured.');
        }

        ['timestamp' => $timestamp, 'signatures' => $signatures] = $this->parseSignatureHeader($header);

        if ($timestamp === null || $signatures === []) {
            throw new InvalidWebhookSignature('Malformed signature header.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $isAuthentic = false;

        foreach ($signatures as $signature) {
            $isAuthentic = hash_equals($expected, $signature) || $isAuthentic;
        }

        if (! $isAuthentic) {
            throw new InvalidWebhookSignature('Signature mismatch.');
        }

        if ($toleranceSeconds > 0 && abs(time() - $timestamp) > $toleranceSeconds) {
            throw new InvalidWebhookSignature('Timestamp outside the tolerance window.');
        }

        $data = json_decode($payload, true);

        if (! is_array($data) || ! is_string($data['name'] ?? null)) {
            throw new InvalidWebhookSignature('Unreadable event payload.');
        }

        $eventId = $data['id'] ?? null;
        $transactionId = str_starts_with($data['name'], 'transaction.')
            ? ($data['entity']['id'] ?? $data['object']['id'] ?? null)
            : null;

        return new WebhookEvent(
            providerEventId: is_scalar($eventId) && (string) $eventId !== '' ? (string) $eventId : 'sha256:'.hash('sha256', $payload),
            name: $data['name'],
            providerTransactionId: is_scalar($transactionId) ? (string) $transactionId : null,
            payload: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPayload(CheckoutRequest $request): array
    {
        $customer = [
            'email' => $request->customerEmail,
            'firstname' => $request->customerFirstName,
            'lastname' => $request->customerLastName,
        ];

        if ($request->customerPhone !== null && preg_match('/^\+228([0-9]{8})$/', $request->customerPhone, $matches) === 1) {
            $customer['phone_number'] = ['number' => $matches[1], 'country' => 'tg'];
        }

        return array_filter([
            'description' => $request->description,
            'amount' => $request->amount,
            'currency' => ['iso' => $request->currency],
            'callback_url' => $request->returnUrl,
            'customer' => $customer,
            'custom_metadata' => $request->metadata,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        $secretKey = (string) config('services.fedapay.secret_key');

        if ($secretKey === '') {
            throw new PaymentProviderException('FedaPay secret key is not configured.');
        }

        $http = Http::baseUrl($this->baseUrl())
            ->withToken($secretKey)
            ->acceptJson()
            ->asJson()
            ->timeout(config()->integer('services.fedapay.timeout', 15));

        try {
            return $call($http)->throw();
        } catch (ConnectionException $exception) {
            throw new PaymentProviderException('FedaPay is unreachable.', previous: $exception);
        } catch (RequestException $exception) {
            throw new PaymentProviderException("FedaPay rejected the request (HTTP {$exception->response->status()}).", previous: $exception);
        }
    }

    /**
     * FedaPay wraps single objects under a "v1/<resource>" key; the OpenAPI schema documents them unwrapped.
     * Both shapes are accepted.
     *
     * @return array<string, mixed>
     */
    private function unwrap(Response $response, ?string $key = null): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new PaymentProviderException('FedaPay returned an unreadable response.');
        }

        foreach ($body as $name => $value) {
            if (is_string($name) && str_starts_with($name, 'v1/') && is_array($value)) {
                return $value;
            }
        }

        if ($key !== null && is_array($body[$key] ?? null)) {
            return $body[$key];
        }

        return $body;
    }

    /**
     * FedaPay's current transaction response may expose only currency_id instead of currency.iso.
     * Resolve that identifier through the currencies endpoint rather than guessing the ISO code.
     *
     * @param  array<string, mixed>  $transaction
     */
    private function transactionCurrency(array $transaction): ?string
    {
        $embedded = data_get($transaction, 'currency.iso');

        if (is_string($embedded) && $embedded !== '') {
            return strtoupper($embedded);
        }

        $currencyId = $transaction['currency_id'] ?? null;

        if (! is_scalar($currencyId) || (string) $currencyId === '') {
            return null;
        }

        $id = rawurlencode((string) $currencyId);
        $currency = $this->unwrap($this->send(fn (PendingRequest $http): Response => $http->get("/currencies/{$id}")));

        $iso = $currency['iso'] ?? null;

        return is_string($iso) && $iso !== '' ? strtoupper($iso) : null;
    }

    private function identifier(mixed $value): string
    {
        if (! is_scalar($value) || (string) $value === '') {
            throw new PaymentProviderException('FedaPay returned no transaction identifier.');
        }

        return (string) $value;
    }

    private function mapStatus(mixed $status): ProviderPaymentStatus
    {
        return match (is_string($status) ? strtolower($status) : '') {
            'approved', 'transferred' => ProviderPaymentStatus::Approved,
            'declined' => ProviderPaymentStatus::Declined,
            'canceled', 'cancelled' => ProviderPaymentStatus::Canceled,
            'expired' => ProviderPaymentStatus::Expired,
            'refunded' => ProviderPaymentStatus::Refunded,
            'pending' => ProviderPaymentStatus::Pending,
            default => ProviderPaymentStatus::Unknown,
        };
    }

    /**
     * @return array{timestamp: int|null, signatures: list<string>}
     */
    private function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 's' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }

    private function baseUrl(): string
    {
        return config('services.fedapay.environment') === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
    }
}
