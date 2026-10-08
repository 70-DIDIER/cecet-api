<?php

namespace App\Domain\Payment;

use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Payment\Exceptions\PaymentException;
use App\Domain\Settings\SettingsService;
use App\Models\User;

/**
 * Decides how a payment is collected from the method the member chose.
 *
 * Each method (T-Money, Flooz, cash, bank transfer) is configured either as "psp" (hosted checkout of the active
 * provider, confirmed automatically) or as "manual" (declared by the member and validated by staff, as on the former
 * site). Switching T-Money to "manual" while the provider contract is pending needs no deployment.
 */
class CollectionRouter
{
    public const MANUAL_PROVIDER = 'manual';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PaymentProviderManager $providers,
    ) {}

    /**
     * @return array{method: string, provider: string, manual: bool, gateway: PaymentProvider|null}
     */
    public function route(User $payer, ?string $method): array
    {
        $enabled = $this->enabledMethods();
        $method = $method ?? ($payer->preferred_payment_method !== null && in_array($payer->preferred_payment_method, $enabled, true) ? $payer->preferred_payment_method : null);
        $modes = $this->settings->array('payment.collection_modes');

        if ($method === null) {
            $method = collect($enabled)->first(fn (string $code): bool => ($modes[$code] ?? null) === 'psp') ?? ($enabled[0] ?? null);
        }

        if ($method === null || ! in_array($method, $enabled, true)) {
            throw PaymentException::methodNotAvailable((string) $method);
        }

        if (($modes[$method] ?? 'manual') === 'manual') {
            return ['method' => $method, 'provider' => self::MANUAL_PROVIDER, 'manual' => true, 'gateway' => null];
        }

        $gateway = $this->providers->inbound();

        return ['method' => $method, 'provider' => $gateway->name(), 'manual' => false, 'gateway' => $gateway];
    }

    /**
     * Instructions shown to the member for a manual payment.
     */
    public function instructions(string $method, int $amount, string $reference): ?string
    {
        $template = $this->settings->array('payment.manual_instructions')[$method] ?? null;

        return is_string($template)
            ? strtr($template, ['{amount}' => number_format($amount, 0, ',', ' '), '{reference}' => $reference])
            : null;
    }

    /**
     * @return list<string>
     */
    private function enabledMethods(): array
    {
        return array_values(collect($this->settings->array('payment.methods'))
            ->filter(fn (mixed $method): bool => is_array($method) && ($method['enabled'] ?? false) === true)
            ->map(fn (array $method): string => (string) ($method['code'] ?? ''))
            ->all());
    }
}
