<?php

use App\Domain\Payout\Data\PayoutRequest;
use App\Domain\Payout\Enums\ProviderPayoutStatus;
use App\Domain\Payout\Exceptions\PayoutProviderException;
use App\Domain\Payout\Providers\FedaPay\FedaPayPayoutProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    seedCatalogs();
    config(['services.fedapay.secret_key' => 'sk_sandbox_test', 'services.fedapay.environment' => 'sandbox']);
    $this->provider = app(FedaPayPayoutProvider::class);
});

function payoutRequest(string $phone = '+22890123456'): PayoutRequest
{
    return new PayoutRequest('PO-0000001-1-1', 500000, 'XOF', $phone, 'Ama', 'Mensah', 'ama@example.com', 'TMONEY');
}

it('creates the payout with our merchant reference, then starts it', function () {
    Http::fake([
        'sandbox-api.fedapay.com/v1/payouts' => Http::response(['v1/payout' => ['id' => 77, 'status' => 'pending']], 201),
        'sandbox-api.fedapay.com/v1/payouts/start' => Http::response([['id' => 77, 'status' => 'started']]),
    ]);

    $result = $this->provider->send(payoutRequest());

    expect($result->providerReference)->toBe('77')
        ->and($result->status)->toBe(ProviderPayoutStatus::Processing)
        ->and($result->needsStart)->toBeFalse();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://sandbox-api.fedapay.com/v1/payouts'
        && $request->method() === 'POST'
        && $request['amount'] === 500000
        && $request['merchant_reference'] === 'PO-0000001-1-1'
        && $request['customer']['phone_number'] === ['number' => '90123456', 'country' => 'tg']);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payouts/start') && $request->method() === 'PUT' && $request->data() === [['id' => 77]]);
});

it('maps the payout statuses', function (string $fedapayStatus, ProviderPayoutStatus $expected, bool $needsStart) {
    Http::fake(['*/payouts/9' => Http::response(['v1/payout' => ['id' => 9, 'status' => $fedapayStatus, 'last_error_code' => $fedapayStatus === 'failed' ? 'INSUFFICIENT_BALANCE' : null]])]);

    $result = $this->provider->fetch('9');

    expect($result->status)->toBe($expected)->and($result->needsStart)->toBe($needsStart);
})->with([
    'sent' => ['sent', ProviderPayoutStatus::Succeeded, false],
    'failed' => ['failed', ProviderPayoutStatus::Failed, false],
    'created but not started' => ['pending', ProviderPayoutStatus::Processing, true],
    'started' => ['started', ProviderPayoutStatus::Processing, false],
    'processing' => ['processing', ProviderPayoutStatus::Processing, false],
    'scheduled' => ['scheduled', ProviderPayoutStatus::Processing, false],
]);

it('keeps the reference of a created payout whose start failed, so it is reconciled and never re-created', function () {
    Http::fake([
        'sandbox-api.fedapay.com/v1/payouts' => Http::response(['v1/payout' => ['id' => 78, 'status' => 'pending']], 201),
        'sandbox-api.fedapay.com/v1/payouts/start' => Http::response(['message' => 'error'], 500),
    ]);

    try {
        $this->provider->send(payoutRequest());
        test()->fail('An exception was expected.');
    } catch (PayoutProviderException $exception) {
        expect($exception->providerReference)->toBe('78');
    }
});

it('finds a payout by our merchant reference, or reports it unknown', function () {
    Http::fake([
        '*/payouts/merchant/PO-KNOWN' => Http::response(['v1/payout' => ['id' => 5, 'status' => 'sent']]),
        '*/payouts/merchant/PO-UNKNOWN' => Http::response(['message' => 'Not found'], 404),
    ]);

    expect($this->provider->findByMerchantReference('PO-KNOWN')?->status)->toBe(ProviderPayoutStatus::Succeeded)
        ->and($this->provider->findByMerchantReference('PO-UNKNOWN'))->toBeNull();
});

it('refuses numbers outside Togo and calls without a secret key', function () {
    Http::fake();

    expect(fn () => $this->provider->send(payoutRequest('+22990123456')))->toThrow(PayoutProviderException::class);

    config(['services.fedapay.secret_key' => '']);
    expect(fn () => $this->provider->fetch('1'))->toThrow(PayoutProviderException::class);

    Http::assertNothingSent();
});
