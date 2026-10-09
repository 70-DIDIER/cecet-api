<?php

namespace App\Domain\Payment;

use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Payment\Providers\Fake\FakePaymentProvider;
use App\Domain\Payment\Providers\FedaPay\FedaPayProvider;
use App\Domain\Settings\SettingsService;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves providers by name. The active inbound provider is a Superadmin setting; late webhooks of a
 * previously active provider can still be authenticated by resolving it by name.
 */
class PaymentProviderManager
{
    public function __construct(
        private readonly Application $container,
        private readonly SettingsService $settings,
    ) {}

    public function provider(string $name): PaymentProvider
    {
        if ($name === FakePaymentProvider::NAME && ! $this->container->isProduction()) {
            return $this->container->make(FakePaymentProvider::class);
        }

        if ($name === FedaPayProvider::NAME) {
            return $this->container->make(FedaPayProvider::class);
        }

        throw PaymentException::unknownProvider($name);
    }

    public function inbound(): PaymentProvider
    {
        return $this->provider($this->settings->string('payment.inbound_provider') ?? '');
    }
}
