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
