<?php

namespace App\Domain\Payout\Providers\FedaPay;

use App\Domain\Payout\Contracts\PayoutProvider;
use App\Domain\Payout\Data\PayoutRequest;
use App\Domain\Payout\Data\PayoutResult;
use App\Domain\Payout\Enums\ProviderPayoutStatus;
use App\Domain\Payout\Exceptions\PayoutProviderException;
use App\Domain\Payout\PayoutCountries;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * FedaPay payouts (REST API v1): create (POST /payouts, status "pending"), then start (PUT /payouts/start).
 *
 * FedaPay documents no payout webhook: the final status ("sent" or "failed") is read by polling. Our merchant
 * reference is sent with each payout so that a lost response can be recovered (GET /payouts/merchant/{reference})
 * instead of creating a second transfer. Field formats to confirm in the sandbox: merchant_reference on creation,
 * and the phone number format (national number + country code "tg" is used, as for collections).
 */
class FedaPayPayoutProvider implements PayoutProvider
{
    public const NAME = 'fedapay';

    private const SANDBOX_URL = 'https://sandbox-api.fedapay.com/v1';

    private const LIVE_URL = 'https://api.fedapay.com/v1';

    public function __construct(private readonly PayoutCountries $countries) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function send(PayoutRequest $request): PayoutResult
    {
        $created = $this->payoutFrom($this->call(fn (PendingRequest $http): Response => $http->post('/payouts', $this->payload($request))));
        $reference = $this->reference($created);

        try {
            return $this->start($reference);
        } catch (PayoutProviderException $exception) {
            // The transfer exists but may not be started: it must be reconciled, never re-created.
            throw new PayoutProviderException($exception->getMessage(), $reference, $exception);
        }
    }

    public function start(string $providerReference): PayoutResult
    {
        $response = $this->call(fn (PendingRequest $http): Response => $http->put('/payouts/start', [['id' => (int) $providerReference]]));

        return $this->toResult($this->payoutFrom($response), $providerReference);
    }

    public function fetch(string $providerReference): PayoutResult
    {
        $id = rawurlencode($providerReference);

        return $this->toResult($this->payoutFrom($this->call(fn (PendingRequest $http): Response => $http->retry(2, 250, throw: false)->get("/payouts/{$id}"))), $providerReference);
    }

    public function findByMerchantReference(string $merchantReference): ?PayoutResult
    {
        $reference = rawurlencode($merchantReference);

        try {
            $payout = $this->payoutFrom($this->call(fn (PendingRequest $http): Response => $http->get("/payouts/merchant/{$reference}")));
        } catch (PayoutProviderException $exception) {
            if ($exception->getPrevious() instanceof RequestException && $exception->getPrevious()->response->status() === 404) {
                return null;
            }

            throw $exception;
        }

        return $this->toResult($payout, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PayoutRequest $request): array
    {
        $customer = array_filter([
            'firstname' => $request->firstName,
            'lastname' => $request->lastName,
            'email' => $request->email,
        ]);

        $country = strtoupper($request->country);
        $dialingCode = $this->countries->dialCode($country);

        if ($dialingCode === null) {
            throw new PayoutProviderException("FedaPay payout country {$country} is not configured by CECT.");
        }

        $pattern = '/^\\+'.preg_quote($dialingCode, '/').'([0-9]{4,12})$/';
        if (preg_match($pattern, $request->phone, $matches) === 1) {
            $customer['phone_number'] = ['number' => $matches[1], 'country' => strtolower($country)];
        } else {
            throw new PayoutProviderException("The payout phone number does not match country {$country}.");
        }

        return [
            'amount' => $request->amount,
            'currency' => ['iso' => $request->currency],
            'customer' => $customer,
            'merchant_reference' => $request->merchantReference,
        ];
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function call(callable $call): Response
    {
        $secretKey = (string) config('services.fedapay.secret_key');

        if ($secretKey === '') {
            throw new PayoutProviderException('FedaPay secret key is not configured.');
        }

        $http = Http::baseUrl(config('services.fedapay.environment') === 'live' ? self::LIVE_URL : self::SANDBOX_URL)
            ->withToken($secretKey)
            ->acceptJson()
            ->asJson()
            ->timeout(config()->integer('services.fedapay.timeout', 15));

        try {
            return $call($http)->throw();
        } catch (ConnectionException $exception) {
            throw new PayoutProviderException('FedaPay is unreachable.', previous: $exception);
        } catch (RequestException $exception) {
            throw new PayoutProviderException("FedaPay rejected the payout request (HTTP {$exception->response->status()}).", previous: $exception);
        }
    }

    /**
     * Single payout from a response: wrapped under "v1/payout", in a list (start), or unwrapped.
     *
     * @return array<string, mixed>
     */
    private function payoutFrom(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new PayoutProviderException('FedaPay returned an unreadable payout response.');
        }

        foreach ($body as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'v1/') && is_array($value)) {
                $body = $value;
                break;
            }
        }

        if (array_is_list($body)) {
            $body = $body[0] ?? null;
        }

        if (! is_array($body)) {
            throw new PayoutProviderException('FedaPay returned no payout.');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $payout
     */
    private function reference(array $payout): string
    {
        $id = $payout['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw new PayoutProviderException('FedaPay returned no payout identifier.');
        }

        return (string) $id;
    }

    /**
     * @param  array<string, mixed>  $payout
     */
    private function toResult(array $payout, ?string $fallbackReference): PayoutResult
    {
        $status = is_string($payout['status'] ?? null) ? strtolower($payout['status']) : '';
        $reference = isset($payout['id']) && is_scalar($payout['id']) ? (string) $payout['id'] : $fallbackReference;

        if ($reference === null || $reference === '') {
            throw new PayoutProviderException('FedaPay returned no payout identifier.');
        }

        return new PayoutResult(
            providerReference: $reference,
            status: match ($status) {
                'sent' => ProviderPayoutStatus::Succeeded,
                'failed' => ProviderPayoutStatus::Failed,
                default => ProviderPayoutStatus::Processing,
            },
            failureCode: is_string($payout['last_error_code'] ?? null) ? $payout['last_error_code'] : null,
            needsStart: $status === 'pending',
        );
    }
}
