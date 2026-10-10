<?php

namespace App\Domain\Payout;

use App\Domain\Payout\Contracts\PayoutProvider;
use App\Domain\Payout\Exceptions\PayoutException;
use App\Domain\Payout\Providers\Fake\FakePayoutProvider;
use App\Domain\Payout\Providers\FedaPay\FedaPayPayoutProvider;
use App\Domain\Settings\SettingsService;
use Illuminate\Contracts\Foundation\Application;

class PayoutProviderManager
{
    public function __construct(
        private readonly Application $app,
        private readonly SettingsService $settings,
    ) {}

    public function provider(string $name): PayoutProvider
    {
        if ($name === FakePayoutProvider::NAME && $this->app->environment('testing')) {
            return $this->app->make(FakePayoutProvider::class);
        }

        if ($name === FedaPayPayoutProvider::NAME) {
            return $this->app->make(FedaPayPayoutProvider::class);
        }

        throw PayoutException::unknownProvider($name);
    }

    public function active(): PayoutProvider
    {
        return $this->provider($this->settings->string('payment.payout_provider') ?? '');
    }
}