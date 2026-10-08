<?php

namespace App\Domain\Payout\Providers\Fake;

use App\Domain\Payout\Contracts\PayoutProvider;
use App\Domain\Payout\Data\PayoutRequest;
use App\Domain\Payout\Data\PayoutResult;
use App\Domain\Payout\Enums\ProviderPayoutStatus;
use App\Domain\Payout\Exceptions\PayoutProviderException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Scriptable payout provider for development and tests. Never resolvable in production.
 *
 * Without a script, every transfer succeeds immediately. script() queues outcomes for the next sends:
 * "succeeded", "failed", "processing", or "lost" (created at the provider but the response never arrives).
 */
class FakePayoutProvider implements PayoutProvider
{
    public const NAME = 'fake';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param  list<'succeeded'|'failed'|'processing'|'lost'>  $outcomes
     */
    public function script(array $outcomes): void
    {
        Cache::forever('fake_payout:script', $outcomes);
    }

    public function send(PayoutRequest $request): PayoutResult
    {
        $script = Cache::get('fake_payout:script', []);
        $outcome = is_array($script) && $script !== [] ? array_shift($script) : 'succeeded';
        Cache::forever('fake_payout:script', $script);

        $reference = 'fpo_'.Str::lower((string) Str::ulid());
        $status = $outcome === 'lost' ? 'processing' : $outcome;

        Cache::put("fake_payout:{$reference}", ['status' => $status, 'amount' => $request->amount, 'phone' => $request->phone], now()->addDays(7));
        Cache::put("fake_payout:merchant:{$request->merchantReference}", $reference, now()->addDays(7));
        $this->recordSent($request);

        if ($outcome === 'lost') {
            throw new PayoutProviderException('Simulated timeout after the transfer was created.');
        }

        return $this->result($reference, $status);
    }

    public function fetch(string $providerReference): PayoutResult
    {
        $state = Cache::get("fake_payout:{$providerReference}");

        if (! is_array($state)) {
            throw new PayoutProviderException('Unknown fake payout.');
        }

        return $this->result($providerReference, (string) $state['status']);
    }

    public function findByMerchantReference(string $merchantReference): ?PayoutResult
    {
        $reference = Cache::get("fake_payout:merchant:{$merchantReference}");

        return is_string($reference) ? $this->fetch($reference) : null;
    }

    public function start(string $providerReference): PayoutResult
    {
        return $this->fetch($providerReference);
    }

    /**
     * Moves a transfer to its final status, as the operator would.
     */
    public function settle(string $providerReference, ProviderPayoutStatus $status): void
    {
        $state = Cache::get("fake_payout:{$providerReference}");

        if (is_array($state)) {
            $state['status'] = $status->value;
            Cache::put("fake_payout:{$providerReference}", $state, now()->addDays(7));
        }
    }

    /**
     * @return list<array{merchant_reference: string, amount: int, phone: string}>
     */
    public function sent(): array
    {
        $sent = Cache::get('fake_payout:sent', []);

        return is_array($sent) ? array_values($sent) : [];
    }

    private function recordSent(PayoutRequest $request): void
    {
        $sent = $this->sent();
        $sent[] = ['merchant_reference' => $request->merchantReference, 'amount' => $request->amount, 'phone' => $request->phone];
        Cache::forever('fake_payout:sent', $sent);
    }

    private function result(string $reference, string $status): PayoutResult
    {
        return new PayoutResult($reference, ProviderPayoutStatus::from($status), $status === 'failed' ? 'SIMULATED_FAILURE' : null);
    }
}
