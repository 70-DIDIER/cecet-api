<?php

use App\Domain\Payment\Data\CheckoutRequest;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payment\Exceptions\InvalidWebhookSignature;
use App\Domain\Payment\Exceptions\PaymentProviderException;
use App\Domain\Payment\Providers\FedaPay\FedaPayProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.fedapay.secret_key' => 'sk_sandbox_test',
        'services.fedapay.webhook_secret' => 'whsec_test',
        'services.fedapay.environment' => 'sandbox',
    ]);
    $this->provider = new FedaPayProvider;
});

function checkoutRequest(?string $phone = '+22890123456'): CheckoutRequest
{
    return new CheckoutRequest(
        attemptId: '01TESTATTEMPT',
        amount: 25000,
        currency: 'XOF',
        description: 'Adhésion CECT CECT-000001',
        customerEmail: 'ama@example.com',
        customerFirstName: 'Ama',
        customerLastName: 'Mensah',
        customerPhone: $phone,
        returnUrl: 'https://app.cect.tg/retour',
        metadata: ['attempt_id' => '01TESTATTEMPT'],
    );
}

/**
 * Signature header built the way the official FedaPay SDK verifies it: t=<unix>,s=HMAC-SHA256("<t>.<body>").
 */
function fedapaySignature(string $body, string $secret = 'whsec_test', ?int $timestamp = null): string
{
    $timestamp ??= time();

    return "t={$timestamp},s=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
}

it('creates a transaction then fetches its payment link', function () {
    Http::fake([
        'sandbox-api.fedapay.com/v1/transactions' => Http::response(['v1/transaction' => ['id' => 4821, 'reference' => 'trx_abc', 'amount' => 25000, 'status' => 'pending']], 201),
        'sandbox-api.fedapay.com/v1/transactions/4821/token' => Http::response(['token' => 'tok_123', 'url' => 'https://checkout-sandbox.fedapay.com/tok_123']),
    ]);

    $session = $this->provider->createCheckout(checkoutRequest());

    expect($session->providerTransactionId)->toBe('4821')
        ->and($session->providerReference)->toBe('trx_abc')
        ->and($session->checkoutUrl)->toBe('https://checkout-sandbox.fedapay.com/tok_123');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://sandbox-api.fedapay.com/v1/transactions'
        && $request->hasHeader('Authorization', 'Bearer sk_sandbox_test')
        && $request['amount'] === 25000
        && $request['currency'] === ['iso' => 'XOF']
        && $request['customer']['phone_number'] === ['number' => '90123456', 'country' => 'tg']
        && $request['custom_metadata'] === ['attempt_id' => '01TESTATTEMPT']
        && $request['callback_url'] === 'https://app.cect.tg/retour');
});

it('accepts unwrapped responses and omits a phone number outside Togo', function () {
    Http::fake([
        'sandbox-api.fedapay.com/v1/transactions' => Http::response(['id' => 7, 'reference' => 'trx_7'], 201),
        'sandbox-api.fedapay.com/v1/transactions/7/token' => Http::response(['token' => 't', 'url' => 'https://pay.example/7']),
    ]);

    expect($this->provider->createCheckout(checkoutRequest('+2250102030405'))->providerTransactionId)->toBe('7');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://sandbox-api.fedapay.com/v1/transactions'
        && ! isset($request['customer']['phone_number']));
});

it('targets the live API when configured for live', function () {
    config(['services.fedapay.environment' => 'live']);
    Http::fake(['api.fedapay.com/v1/*' => Http::response(['v1/transaction' => ['id' => 1, 'amount' => 100, 'currency' => ['iso' => 'XOF'], 'status' => 'pending']])]);

    $this->provider->fetchTransaction('1');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.fedapay.com/v1/transactions/1'));
});

it('maps provider statuses and normalizes the amount', function (string $fedapayStatus, ProviderPaymentStatus $expected) {
    Http::fake(['sandbox-api.fedapay.com/v1/transactions/55' => Http::response([
        'v1/transaction' => ['id' => 55, 'status' => $fedapayStatus, 'amount' => 25000.0, 'currency' => ['iso' => 'xof'], 'reference' => 'trx_55', 'custom_metadata' => ['attempt_id' => 'A1']],
    ])]);

    $transaction = $this->provider->fetchTransaction('55');

    expect($transaction->status)->toBe($expected)
        ->and($transaction->amount)->toBe(25000)
        ->and($transaction->currency)->toBe('XOF')
        ->and($transaction->metadata)->toBe(['attempt_id' => 'A1']);
})->with([
    'approved' => ['approved', ProviderPaymentStatus::Approved],
    'transferred' => ['transferred', ProviderPaymentStatus::Approved],
    'pending' => ['pending', ProviderPaymentStatus::Pending],
    'declined' => ['declined', ProviderPaymentStatus::Declined],
    'canceled' => ['canceled', ProviderPaymentStatus::Canceled],
    'refunded' => ['refunded', ProviderPaymentStatus::Refunded],
    'something new' => ['frozen', ProviderPaymentStatus::Unknown],
]);

it('fails closed on provider errors, timeouts and incomplete data', function (callable $fake) {
    $fake();

    expect(fn () => $this->provider->fetchTransaction('9'))->toThrow(PaymentProviderException::class);
})->with([
    'unauthorized' => [fn () => Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)])],
    'server error' => [fn () => Http::fake(['*' => Http::response('', 500)])],
    'incomplete transaction' => [fn () => Http::fake(['*' => Http::response(['v1/transaction' => ['id' => 9, 'status' => 'approved']])])],
    'not json' => [fn () => Http::fake(['*' => Http::response('<html>', 200)])],
    'connection failure' => [fn () => Http::fake(['*' => fn () => throw new ConnectionException('timeout')])],
]);

it('refuses to call the API without a secret key', function () {
    config(['services.fedapay.secret_key' => '']);
    Http::fake();

    expect(fn () => $this->provider->createCheckout(checkoutRequest()))->toThrow(PaymentProviderException::class);
    Http::assertNothingSent();
});

it('does not return a checkout when the payment link is missing', function () {
    Http::fake([
        'sandbox-api.fedapay.com/v1/transactions' => Http::response(['v1/transaction' => ['id' => 3]], 201),
        'sandbox-api.fedapay.com/v1/transactions/3/token' => Http::response(['token' => 'x']),
    ]);

    expect(fn () => $this->provider->createCheckout(checkoutRequest()))->toThrow(PaymentProviderException::class, 'payment link');
});

describe('webhook signature', function () {
    $body = (string) json_encode(['id' => 99, 'name' => 'transaction.approved', 'object' => 'event', 'entity' => ['id' => 4821, 'status' => 'approved']]);

    it('accepts a correctly signed event', function () use ($body) {
        $event = $this->provider->parseWebhook($body, ['x-fedapay-signature' => fedapaySignature($body)], 300);

        expect($event->providerEventId)->toBe('99')
            ->and($event->name)->toBe('transaction.approved')
            ->and($event->providerTransactionId)->toBe('4821');
    });

    it('accepts any matching signature when several are present (secret rotation)', function () use ($body) {
        $current = fedapaySignature($body);
        $header = $current.',s='.str_repeat('0', 64);

        expect($this->provider->parseWebhook($body, ['x-fedapay-signature' => $header], 300)->name)->toBe('transaction.approved');
    });

    it('rejects a tampered body, a wrong secret, a missing or malformed header', function (string $header, ?string $tamper) use ($body) {
        $signed = $header === 'valid' ? fedapaySignature($body) : ($header === 'wrong-secret' ? fedapaySignature($body, 'other') : $header);
        $received = $tamper === null ? $body : str_replace('4821', '9999', $body);

        expect(fn () => $this->provider->parseWebhook($received, ['x-fedapay-signature' => $signed], 300))->toThrow(InvalidWebhookSignature::class);
    })->with([
        'tampered body' => ['valid', 'tamper'],
        'wrong secret' => ['wrong-secret', null],
        'empty header' => ['', null],
        'no timestamp' => ['s=abcdef', null],
        'no signature' => ['t=123', null],
        'garbage' => ['hello', null],
    ]);

    it('rejects a replayed event outside the tolerance window, unless tolerance is disabled', function () use ($body) {
        $old = fedapaySignature($body, timestamp: time() - 3600);

        expect(fn () => $this->provider->parseWebhook($body, ['x-fedapay-signature' => $old], 300))->toThrow(InvalidWebhookSignature::class)
            ->and($this->provider->parseWebhook($body, ['x-fedapay-signature' => $old], 0)->name)->toBe('transaction.approved');
    });

    it('rejects every event when no webhook secret is configured', function () use ($body) {
        config(['services.fedapay.webhook_secret' => '']);

        expect(fn () => $this->provider->parseWebhook($body, ['x-fedapay-signature' => fedapaySignature($body, '')], 300))->toThrow(InvalidWebhookSignature::class);
    });

    it('falls back to a body hash as event id and ignores non-transaction events', function () {
        $body = (string) json_encode(['name' => 'customer.created', 'entity' => ['id' => 5]]);

        $event = $this->provider->parseWebhook($body, ['x-fedapay-signature' => fedapaySignature($body)], 300);

        expect($event->providerEventId)->toBe('sha256:'.hash('sha256', $body))
            ->and($event->providerTransactionId)->toBeNull();
    });
});
