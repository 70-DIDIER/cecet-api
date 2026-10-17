<?php

use App\Domain\Payment\AfricanMobileMoneyCatalog as Catalog;

it('recognizes all 54 sovereign African ISO countries and eight UEMOA countries', function () {
    expect(Catalog::CALLING_CODES)->toHaveCount(54)
        ->and(Catalog::UEMOA)->toHaveCount(8);
    foreach (Catalog::UEMOA as $country) {
        expect(Catalog::COUNTRY_NETWORKS[$country] ?? [])->not->toBeEmpty();
    }
});

it('filters every UEMOA operator to its documented country', function () {
    $methods = Catalog::methods(true);
    foreach (Catalog::UEMOA as $country) {
        $configured = array_values(array_filter($methods, fn ($method) =>
            $method['channel'] === 'mobile_money' && in_array($country, $method['countries'], true)));
        expect($configured)->not->toBeEmpty();
        foreach (Catalog::COUNTRY_NETWORKS[$country] as $code) {
            expect(array_column($configured, 'code'))->toContain($code);
        }
    }
});

it('matches E164 dialing prefixes across the African continent', function () {
    foreach (Catalog::CALLING_CODES as $country => $dial) {
        expect(Catalog::countryFromPhone('+'.$dial.'123456789'))->toBe($country);
    }
    expect(Catalog::countryFromPhone('0022890123456'))->toBeNull()
        ->and(Catalog::countryFromPhone('+819012345678'))->toBeNull();
});

it('keeps new African payment providers disabled in production', function () {
    $staging = Catalog::methods(true);
    $production = Catalog::methods(false);
    expect(count($staging))->toBeGreaterThan(35);
    foreach ($production as $method) {
        if ($method['channel'] === 'mobile_money' && ! in_array($method['code'], ['TMONEY', 'FLOOZ'], true)) {
            expect($method['enabled'])->toBeFalse();
        }
    }
    foreach (Catalog::mobileCollectionModes() as $code => $mode) {
        expect($mode)->toBe(in_array($code, ['CASH', 'BANK_TRANSFER'], true) ? 'manual' : 'psp');
    }
});

it('offers a generic test-only mobile wallet for all 54 African countries', function () {
    $fallback = collect(Catalog::methods(true))->firstWhere('code', Catalog::GENERIC_TEST_WALLET);
    expect($fallback)->not->toBeNull()
        ->and($fallback['countries'])->toHaveCount(54)
        ->and($fallback['label'])->toContain('simulation');
    expect(collect(Catalog::methods(false))->firstWhere('code', Catalog::GENERIC_TEST_WALLET))->toBeNull();
});
