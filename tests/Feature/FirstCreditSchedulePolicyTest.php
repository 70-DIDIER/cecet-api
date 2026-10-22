<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Models\PackVersion;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Subscription\Models\PackPeriod;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedCatalogs();
    Mail::fake();
});

it('snapshots the family-default first-credit timing in new pack versions for PF and PM', function (): void {
    foreach ([
        [PackFamily::Vendeur, 'VD1', 1, false],
        [PackFamily::Consommateur, 'CD1', 0, false],
        [PackFamily::Marchand, 'MD1', 0, true],
    ] as [$family, $code, $delay, $legal]) {
        $plan = makePlan($family, $code, periodicTerms([
            'price_xof' => 65000,
            'quota_per_period' => '35',
            'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 3],
        ]), sellableImmediately: false);

        expect($plan->currentVersion->rules['first_credit_delay_periods'])->toBe($delay);
        $member = $legal ? User::factory()->legalPerson()->create() : User::factory()->create();
        $catalog = $this->withToken(tokenFor($member))->getJson("/api/v1/packs/{$plan->id}")->assertOk();
        $catalog->assertJsonPath('data.current_version.first_credit_delay_periods', $delay);

        $pack = subscribeAndPay($member, $plan)->load(['periods', 'account']);
        expect($pack->periods)->toHaveCount(3);

        $first = $pack->periods->first();
        $calculated = $pack->version->period_unit->addTo($pack->activated_at->toImmutable(), $delay);
        expect($first->starts_at->toDateString())->toBe($calculated->toDateString())
            ->and($pack->account->available)->toBe($delay === 0 ? '35.000000' : '0.000000')
            ->and(QuotaLedgerEntry::query()->where('user_pack_id', $pack->id)->count())->toBe($delay === 0 ? 1 : 0);

        // 3 month-long distributions plus the initial waiting period, not a silently shortened entitlement.
        $lastEnd = $pack->version->period_unit->addTo($pack->activated_at->toImmutable(), $delay + 3);
        expect($pack->expires_at->toDateString())->toBe($lastEnd->toDateString());
    }
});

it('allows a Superadmin to override the delay per new version while leaving existing contracts unchanged', function (): void {
    $plan = makePlan(PackFamily::Consommateur, 'CD2', periodicTerms([
        'price_xof' => 100000,
        'quota_per_period' => '25',
        'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 4],
        'rules' => ['first_credit_delay_periods' => 2],
    ]));
    $member = User::factory()->create();
    $originalPack = subscribeAndPay($member, $plan)->load(['account', 'periods']);
    $originalVersionId = $originalPack->pack_version_id;
    $originalFirstDate = $originalPack->periods->first()->starts_at->toIso8601String();
    expect($originalPack->account->available)->toBe('0.000000');

    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    $newVersion = $this->withToken(tokenFor($superadmin, withStepUp: true))
        ->postJson("/api/v1/admin/packs/plans/{$plan->id}/versions", periodicTerms([
            'price_xof' => 110000,
            'quota_per_period' => '25',
            'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 4],
            'rules' => ['first_credit_delay_periods' => 0],
        ]), ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()
        ->assertJsonPath('data.first_credit_delay_periods', 0)
        ->json('data.id');

    $this->withToken(tokenFor($superadmin, withStepUp: true))
        ->postJson("/api/v1/admin/packs/plans/{$plan->id}/versions/{$newVersion}/publish")
        ->assertOk();

    $originalPack->refresh()->load('periods');
    expect($originalPack->pack_version_id)->toBe($originalVersionId)
        ->and($originalPack->periods->first()->starts_at->toIso8601String())->toBe($originalFirstDate)
        ->and(PackVersion::query()->findOrFail($originalVersionId)->rules['first_credit_delay_periods'])->toBe(2)
        ->and(PackVersion::query()->findOrFail($newVersion)->rules['first_credit_delay_periods'])->toBe(0);

    $newPack = subscribeAndPay(User::factory()->create(), $plan)->load(['account', 'periods']);
    expect($newPack->version->id)->toBe($newVersion)
        ->and($newPack->account->available)->toBe('25.000000')
        ->and($newPack->periods->first()->credited_at)->not->toBeNull();
});

it('validates the configured delay and prohibits periodic timing for Personnel packs', function (): void {
    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    $seller = makePlan(PackFamily::Vendeur, 'VD3');
    foreach ([-1, 13, 1.5, 'tomorrow'] as $invalid) {
        $this->withToken(tokenFor($superadmin, withStepUp: true))
            ->postJson("/api/v1/admin/packs/plans/{$seller->id}/versions", periodicTerms([
                'rules' => ['first_credit_delay_periods' => $invalid],
            ]), ['Idempotency-Key' => (string) Str::ulid()])
            ->assertStatus(422)
            ->assertJsonStructure(['details' => ['fields' => ['rules.first_credit_delay_periods']]]);
    }

    $personal = makePlan(PackFamily::Personnel, 'PD3');
    $this->withToken(tokenFor($superadmin, withStepUp: true))
        ->postJson("/api/v1/admin/packs/plans/{$personal->id}/versions", personnelTerms([
            'rules' => ['first_credit_delay_periods' => 1],
        ]), ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(422)
        ->assertJsonStructure(['details' => ['fields' => ['rules.first_credit_delay_periods']]]);
});

it('credits a delayed allowance only when due, and never twice, including after a roller rerun', function (): void {
    $plan = makePlan(PackFamily::Marchand, 'MD4', periodicTerms([
        'price_xof' => 100000,
        'quota_per_period' => '50',
        'period' => ['unit' => 'week', 'every' => 2, 'cycles' => 2],
        'rules' => ['first_credit_delay_periods' => 2],
    ]));
    $pack = subscribeAndPay(User::factory()->legalPerson()->create(), $plan)->load(['periods', 'account']);
    $first = $pack->periods->first();
    expect($pack->account->available)->toBe('0.000000')
        ->and($first->credited_at)->toBeNull()
        ->and($first->starts_at->greaterThan($pack->activated_at))->toBeTrue();

    $this->travelTo($first->starts_at->copy()->subSecond());
    $this->artisan('packs:roll-periods')->assertSuccessful();
    expect(QuotaLedgerEntry::query()->where('user_pack_id', $pack->id)->count())->toBe(0);

    $this->travelTo($first->starts_at->copy()->addSecond());
    $this->artisan('packs:roll-periods')->assertSuccessful();
    $this->artisan('packs:roll-periods')->assertSuccessful();

    expect($pack->account()->firstOrFail()->available)->toBe('50.000000')
        ->and(QuotaLedgerEntry::query()->where('user_pack_id', $pack->id)->count())->toBe(1)
        ->and(PackPeriod::query()->where('user_pack_id', $pack->id)->whereNotNull('credited_at')->count())->toBe(1);

    $this->travelBack();
});

it('keeps an older published version without a delay rule on the historical family default', function (): void {
    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    $catalog = app(\App\Domain\Catalog\CatalogManager::class);
    $plan = $catalog->createPlan($superadmin, PackFamily::Vendeur, 'VL5', 'Vendeur historique', 1, null);
    // Build a historical fixture *at insertion time*. The database forbids
    // changing ANY version terms even before publication, by design.
    $terms = app(\App\Domain\Catalog\PackVersionValidator::class)->validate(PackFamily::Vendeur, periodicTerms([
        'price_xof' => 100000,
        'quota_per_period' => '60',
        'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 3],
    ]));
    $version = PackVersion::query()->create([
        ...$terms,
        'pack_plan_id' => $plan->id,
        'version' => 1,
        'currency' => 'XOF',
        'rules' => [],
        'created_by' => $superadmin->id,
        'created_at' => now(),
    ]);
    $catalog->publishVersion($superadmin, $plan, $version);
    $catalog->transition($superadmin, $plan, \App\Domain\Catalog\Enums\PackPlanStatus::Published, 'Conservation contrat historique');
    expect($version->refresh()->rules)->toBe([]);

    $member = User::factory()->create();
    $this->withToken(tokenFor($member))->getJson("/api/v1/packs/{$plan->id}")
        ->assertOk()->assertJsonPath('data.current_version.first_credit_delay_periods', 1);

    $pack = subscribeAndPay($member, $plan)->load(['account', 'periods']);
    expect($pack->account->available)->toBe('0.000000')
        ->and($pack->periods->first()->starts_at->toDateString())
        ->toBe($pack->activated_at->addMonth()->toDateString())
        ->and($pack->periods)->toHaveCount(3);
});
