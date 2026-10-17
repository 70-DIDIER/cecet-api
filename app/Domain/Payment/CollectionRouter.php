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
    public function route(User $payer, ?string $method, ?string $paymentPhone = null): array
    {
        // The chosen payment phone, if supplied, controls available operators.
        // A mobile provider cannot be offered for a country where CECT has not enabled it.
        $phone = $paymentPhone ?? $payer->phone;
        $country = match (true) {
            is_string($phone) && str_starts_with($phone, '+228') => 'TG',
            is_string($phone) && str_starts_with($phone, '+221') => 'SN',
            is_string($phone) && str_starts_with($phone, '+225') => 'CI',
            default => null,
        };
        $enabled = $this->enabledMethods($country);
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
    private function enabledMethods(?string $country): array
    {
        return array_values(collect($this->settings->array('payment.methods'))
            ->filter(function (mixed $method) use ($country): bool {
                if (! is_array($method) || ($method['enabled'] ?? false) !== true) {
                    return false;
                }
                $countries = $method['countries'] ?? [];
                return ! is_array($countries) || $countries === [] ||
                    ($country !== null && in_array($country, $countries, true));
            })
            ->map(fn (array $method): string => (string) ($method['code'] ?? ''))
            ->all());
    }
}
