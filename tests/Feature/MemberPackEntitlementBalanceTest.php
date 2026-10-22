<?php

use App\Domain\Catalog\Enums\PackFamily;
use App\Models\User;

beforeEach(fn () => seedCatalogs());

it('separates total contractual YEM from unlocked available YEM for a delayed seller', function () {
    $member = User::factory()->create();
    $plan = makePlan(PackFamily::Vendeur, 'TOT1', periodicTerms([
        'price_xof' => 100000,
        'period' => ['unit' => 'half_month', 'every' => 1, 'cycles' => 24],
        'quota_per_period' => '40',
        'rules' => ['first_credit_delay_periods' => 1],
    ]));
    subscribeAndPay($member, $plan);
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/dashboard')->assertOk()
        ->assertJsonPath('data.active_packs.0.contractual_total', '960.000000')
        ->assertJsonPath('data.active_packs.0.credited', '0.000000')
        ->assertJsonPath('data.active_packs.0.available', '0.000000')
        ->assertJsonPath('data.active_packs.0.reserved', '0.000000')
        ->assertJsonPath('data.active_packs.0.consumed', '0.000000')
        ->assertJsonPath('data.active_packs.0.period.cycles', 24);
});

it('counts cash-backed consumer bonuses in the lifetime rights but not in unused future YEM', function () {
    $member = User::factory()->create();
    $plan = makePlan(PackFamily::Consommateur, 'TOT2', periodicTerms([
        'price_xof' => 300000,
        'quota_per_period' => '20',
        'period' => ['unit' => 'half_month', 'every' => 1, 'cycles' => 24],
        'rules' => ['first_credit_delay_periods' => 0,'cashback' => ['enabled' => true, 'rate_bps' => 800, 'rounding' => 'down', 'max_yem' => null]],
    ]));
    subscribeAndPay($member, $plan);
    $response = $this->withToken(tokenFor($member))->getJson('/api/v1/me/dashboard')->assertOk();
    $total = (float) $response->json('data.active_packs.0.contractual_total');
    $credited = (float) $response->json('data.active_packs.0.credited');
    expect($total)->toBeGreaterThan(480.0)->and($credited)->toBeGreaterThan(20.0)->and($credited)->toBeLessThan($total);
});
