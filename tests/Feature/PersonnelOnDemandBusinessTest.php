<?php

use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Quota\Models\QuotaLedgerEntry;
use App\Domain\Subscription\Models\PackPeriod;
use App\Models\User;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use Illuminate\Support\Str;

beforeEach(fn () => seedCatalogs());

it('publishes one on-demand Personnel plan without any periodic schedule and without duplicates', function (): void {
    $this->artisan('db:seed', ['--class' => \Database\Seeders\PersonnelOnDemandSeeder::class])->assertSuccessful();
    $this->artisan('db:seed', ['--class' => \Database\Seeders\PersonnelOnDemandSeeder::class])->assertSuccessful();
    $plan = PackPlan::query()->with('currentVersion')->where('code', 'PRS-LIBRE')->sole();
    expect($plan->currentVersion->price_xof)->toBe(0)
        ->and($plan->currentVersion->period_unit)->toBeNull()
        ->and($plan->currentVersion->period_cycles)->toBeNull()
        ->and($plan->currentVersion->rules['min_topup_xof'])->toBe(650)
        ->and(PackPlan::query()->where('code', 'PRS-LIBRE')->count())->toBe(1);

    $person = User::factory()->create();
    $subscribe = $this->withToken(tokenFor($person))->postJson('/api/v1/me/packs', ['plan_id' => $plan->id], ['Idempotency-Key' => (string) Str::ulid()]);
    $subscribe->assertCreated()->assertJsonPath('data.user_pack.status', 'active')->assertJsonPath('data.payment', null);
    $packId = $subscribe->json('data.user_pack.id');
    expect(PackPeriod::query()->where('user_pack_id', $packId)->count())->toBe(0)
        ->and(QuotaLedgerEntry::query()->where('user_pack_id', $packId)->count())->toBe(0);
    $this->withToken(tokenFor($person))->getJson("/api/v1/me/packs/{$packId}")->assertOk()->assertJsonPath('data.periods', []);
    $topup = $this->withToken(tokenFor($person))->postJson("/api/v1/me/packs/{$packId}/topups", ['amount_xof' => 6500, 'method' => 'TMONEY'], ['Idempotency-Key' => (string) Str::ulid()])->assertCreated();
    expect(QuotaLedgerEntry::query()->where('user_pack_id', $packId)->count())->toBe(0);
    settleFakeAttempt($topup->json('data.payment.id'), ProviderPaymentStatus::Approved)->assertOk();
    $this->withToken(tokenFor($person))->getJson("/api/v1/me/packs/{$packId}")->assertOk()->assertJsonPath('data.balance.available', '10.000000');
    expect(PackPeriod::query()->where('user_pack_id', $packId)->count())->toBe(0);
});
