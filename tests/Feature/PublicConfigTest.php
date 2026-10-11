<?php

beforeEach(function () {
    seedCatalogs();
});

it('exposes the public configuration to visitors', function () {
    $data = $this->getJson('/api/v1/config')->assertOk()->json('data');

    expect($data['currency'])->toBe('XOF')
        ->and($data['test_mode'])->toBeTrue()
        ->and($data['membership_fees'])->toHaveKeys(['natural_person', 'legal_person'])
        ->and($data['membership_fees']['natural_person'])->toBeInt()
        ->and($data['rates_xof_per_yem'])->toHaveKeys(['purchase', 'sale', 'transfer'])
        ->and($data['legal_forms'])->toContain('SARL')
        ->and(collect($data['payment_methods'])->pluck('code')->all())->toContain('TMONEY', 'CASH')
        ->and($data['payment_methods'][0])->toHaveKeys(['code', 'label', 'channel', 'countries'])
        ->and($data['payment_methods'][0]['countries'])->toContain('TG')
        ->and($data['content'])->toHaveKeys(['key_figures', 'faq', 'news', 'partners'])
        ->and($data['content']['key_figures'])->not->toBeEmpty()
        ->and(collect($data['content']['faq'])->every(fn (array $item): bool => $item['published'] === true))->toBeTrue();
});

it('never exposes secrets or provider settings', function () {
    $body = json_encode($this->getJson('/api/v1/config')->assertOk()->json());

    expect($body)->not->toContain('provider')->and($body)->not->toContain('secret');
});
