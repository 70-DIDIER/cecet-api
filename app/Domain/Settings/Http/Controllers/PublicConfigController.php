<?php

namespace App\Domain\Settings\Http\Controllers;

use App\Domain\Settings\SettingsService;
use Illuminate\Http\JsonResponse;

class PublicConfigController
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Public configuration used by the website: membership fees, indicative rates, legal forms and payment methods.
     */
    public function __invoke(): JsonResponse
    {
        $methods = collect($this->settings->array('payment.methods'))
            ->filter(fn (mixed $method): bool => is_array($method) && ($method['enabled'] ?? false) === true)
            ->map(fn (array $method): array => [
                'code' => $method['code'],
                'label' => $method['label'],
                'channel' => $method['channel'],
                'countries' => array_values(array_map(fn (mixed $country): string => strtoupper((string) $country), $method['countries'] ?? ['TG'])),
            ])
            ->values()
            ->all();

        return response()->json(['data' => [
            'currency' => 'XOF',
            'test_mode' => app()->environment(['local', 'testing', 'staging']),
            'membership_fees' => [
                'natural_person' => $this->settings->integer('membership.fee.natural_person'),
                'legal_person' => $this->settings->integer('membership.fee.legal_person'),
            ],
            'rates_xof_per_yem' => [
                'purchase' => $this->settings->integer('rates.purchase_xof_per_yem'),
                'sale' => $this->settings->integer('rates.sale_xof_per_yem'),
                'transfer' => $this->settings->integer('rates.transfer_xof_per_yem'),
            ],
            'legal_forms' => $this->settings->array('organization.legal_forms'),
            'rccm_required' => $this->settings->boolean('organization.rccm_required'),
            'nif_required' => $this->settings->boolean('organization.nif_required'),
            'payment_methods' => $methods,
            'content' => [
                'key_figures' => $this->settings->array('content.key_figures'),
                'faq' => collect($this->settings->array('content.faq'))->filter(fn (mixed $item): bool => is_array($item) && ($item['published'] ?? false) === true)->values()->all(),
                'news' => collect($this->settings->array('content.news'))->filter(fn (mixed $item): bool => is_array($item) && ($item['published'] ?? false) === true)->sortByDesc('date')->values()->all(),
                'partners' => collect($this->settings->array('content.partners'))->filter(fn (mixed $item): bool => is_array($item) && ($item['published'] ?? false) === true)->values()->all(),
            ],
        ]]);
    }
}
