<?php

use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Catalog\Models\PackVersion;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedCatalogs();
});

it('imports the PDF source with 29 published versions and one correctly blocked incomplete merchant pack', function () {
    $this->artisan('db:seed', ['--class' => \Database\Seeders\ReceivedPdfCatalogSeeder::class])->assertSuccessful();

    $plans = PackPlan::query()->get();
    expect($plans)->toHaveCount(30);

    expect(DB::table('pack_plans')->where('status', 'published')->count())->toBe(29)
        ->and(DB::table('pack_plans')->where('status', 'draft')->count())->toBe(1)
        ->and(DB::table('pack_versions')->where('period_unit', 'half_month')->count())->toBe(29);

    $seller = PackPlan::query()->where('code', 'ASC-01')->with('currentVersion')->sole();
    expect($seller->name)->toBe('Éclaireur')
        ->and($seller->currentVersion->price_xof)->toBe(100000)
        ->and($seller->currentVersion->quota_per_period)->toBe('40.000000')
        ->and($seller->currentVersion->period_cycles)->toBe(24)
        ->and($seller->currentVersion->rules['first_credit_delay_periods'])->toBe(1);

    $merchant = PackPlan::query()->where('code', 'CPT-01')->with('currentVersion')->sole();
    expect($merchant->currentVersion->quota_per_period)->toBe('250.000000')
        ->and($merchant->currentVersion->price_xof)->toBe(240000)
        ->and($merchant->currentVersion->eligible_person_types)->toBe(['legal']);

    $incomplete = PackPlan::query()->where('code', 'CPT-06')->with('currentVersion')->sole();
    expect($incomplete->name)->toBe('Maître Marchand')
        ->and($incomplete->status->value)->toBe('draft')
        ->and($incomplete->currentVersion)->toBeNull();

    $consumer = PackPlan::query()->where('code', 'CLT-01')->with('currentVersion')->sole();
    expect($consumer->currentVersion->price_xof)->toBe(300000)
        ->and($consumer->currentVersion->rules['cashback']['rate_bps'])->toBe(800)
        ->and($consumer->currentVersion->eligible_person_types)->toBe(['natural']);

    // Repeating an operator import must not duplicate or mutate active versions.
    $this->artisan('db:seed', ['--class' => \Database\Seeders\ReceivedPdfCatalogSeeder::class])->assertSuccessful();
    expect(PackPlan::query()->count())->toBe(30)
        ->and(PackVersion::query()->count())->toBe(29);
});

it('computes 24 quinzaines as exactly twelve anchored calendar months without drift', function () {
    $unit = \App\Domain\Catalog\Enums\PeriodUnit::HalfMonth;
    $anchor = \Carbon\CarbonImmutable::parse('2026-01-31 12:00:00Z');

    expect($unit->addTo($anchor, 0)->toDateString())->toBe('2026-01-31')
        ->and($unit->addTo($anchor, 1)->toDateString())->toBe('2026-02-15')
        ->and($unit->addTo($anchor, 2)->toDateString())->toBe('2026-02-28')
        ->and($unit->addTo($anchor, 24)->toDateString())->toBe('2027-01-31')
        ->and($unit->addTo($anchor, 25)->toDateString())->toBe('2027-02-15');
});

it('makes imported published PF and PM packs subscribable with accurate recurring YEM credits and cashback', function () {
    $this->artisan('db:seed', ['--class' => \Database\Seeders\ReceivedPdfCatalogSeeder::class])->assertSuccessful();

    $seller = PackPlan::query()->where('code', 'ASC-01')->sole();
    $consumer = PackPlan::query()->where('code', 'CLT-01')->sole();
    $merchant = PackPlan::query()->where('code', 'CPT-01')->sole();
    $person = \App\Models\User::factory()->create();
    $organization = \App\Models\User::factory()->legalPerson()->create();

    $this->withToken(tokenFor($person))->getJson('/api/v1/me/catalog-packs')->assertOk();
    $this->withToken(tokenFor($organization))->getJson('/api/v1/me/catalog-packs')->assertOk();

    $sellerPack = subscribeAndPay($person, $seller)->load(['account', 'periods']);
    expect($sellerPack->price_xof)->toBe(100000)
        ->and($sellerPack->account->available)->toBe('0.000000')
        ->and($sellerPack->periods)->toHaveCount(24)
        ->and($sellerPack->periods->first()->starts_at->greaterThan($sellerPack->activated_at))->toBeTrue();

    $consumerPack = subscribeAndPay($person, $consumer)->load(['account', 'periods']);
    expect($consumerPack->price_xof)->toBe(300000)
        ->and($consumerPack->periods)->toHaveCount(24)
        ->and($consumerPack->account->available)->not->toBe('0.000000')
        ->and($consumerPack->yem_cashback)->not->toBe('0.000000')
        ->and($consumerPack->cashback_bps)->toBe(800);

    $merchantPack = subscribeAndPay($organization, $merchant)->load(['account', 'periods']);
    expect($merchantPack->price_xof)->toBe(240000)
        ->and($merchantPack->periods)->toHaveCount(24)
        ->and($merchantPack->account->available)->toBe('250.000000')
        ->and($merchantPack->expires_at->greaterThan($merchantPack->activated_at->copy()->addMonths(11)))->toBeTrue();

    // A member cannot evade the legal-person merchant-only rule through a direct API call.
    $this->withToken(tokenFor($person))
        ->postJson('/api/v1/me/packs', ['plan_id' => $merchant->id], ['Idempotency-Key' => (string) \Illuminate\Support\Str::ulid()])
        ->assertStatus(422);
    $this->withToken(tokenFor($organization))
        ->postJson('/api/v1/me/packs', ['plan_id' => $consumer->id], ['Idempotency-Key' => (string) \Illuminate\Support\Str::ulid()])
        ->assertStatus(422);
});
