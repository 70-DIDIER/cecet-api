<?php

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Models\PackPlan;
use App\Domain\Catalog\Models\PackVersion;
use App\Domain\Quota\QuotaLedger;
use App\Domain\Subscription\Models\PackPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => seedCatalogs());

it('adds one clearly marked staging-only Vendeur pack without touching published source PDF offers', function () {
    $this->artisan('db:seed', ['--class' => \Database\Seeders\VendeurHorizonDemoSeeder::class])->assertSuccessful();
    $this->artisan('db:seed', ['--class' => \Database\Seeders\VendeurHorizonDemoSeeder::class])->assertSuccessful();

    $demo = PackPlan::query()->with('currentVersion')->where('code', 'TST-VND-HORIZON')->sole();
    $terms = $demo->currentVersion;
    expect($demo->name)->toBe('Vendeur Horizon — TEST')
        ->and($demo->family)->toBe(PackFamily::Vendeur)
        ->and($demo->status->value)->toBe('published')
        ->and($terms->price_xof)->toBe(100000)
        ->and($terms->period_unit->value)->toBe('half_month')
        ->and($terms->period_cycles)->toBe(24)
        ->and($terms->quota_per_period)->toBe('10000.000000')
        ->and($terms->rules['first_credit_delay_periods'])->toBe(0)
        ->and($terms->max_operation)->toBe('10000.000000')
        ->and($terms->eligible_person_types)->toBe(['natural'])
        ->and(PackPlan::query()->where('code', 'TST-VND-HORIZON')->count())->toBe(1)
        ->and(PackVersion::query()->where('pack_plan_id', $demo->id)->count())->toBe(1);

    $member = User::factory()->create();
    $legal = User::factory()->legalPerson()->create();
    $this->withToken(tokenFor($member))->getJson('/api/v1/me/catalog-packs')->assertOk();
    $this->withToken(tokenFor($legal))->postJson('/api/v1/me/packs', ['plan_id' => $demo->id], ['Idempotency-Key' => (string) \Illuminate\Support\Str::ulid()])->assertStatus(422);

    $pack = subscribeAndPay($member, $demo)->refresh()->load(['account','periods']);
    expect($pack->status->value)->toBe('active')
        ->and($pack->periods)->toHaveCount(24)
        ->and($pack->periods->first()->credited_at)->not->toBeNull()
        ->and($pack->account->available)->toBe('10000.000000')
        ->and($pack->account->reserved)->toBe('0.000000')
        ->and($pack->account->consumed)->toBe('0.000000');

    app(QuotaLedger::class)->reserve($pack, '2500', 'demo:test:sale:reserve');
    expect($pack->account->fresh()->available)->toBe('7500.000000')
        ->and($pack->account->fresh()->reserved)->toBe('2500.000000');
    app(QuotaLedger::class)->consume($pack, '1500', 'demo:test:sale:consume');
    app(QuotaLedger::class)->release($pack, '1000', 'demo:test:sale:release');
    expect($pack->account->fresh()->available)->toBe('8500.000000')
        ->and($pack->account->fresh()->reserved)->toBe('0.000000')
        ->and($pack->account->fresh()->consumed)->toBe('1500.000000');
});

it('never seeds demonstration offers in production mode', function () {
    app()->detectEnvironment(fn () => 'production');
    try {
        expect(fn () => app(\Database\Seeders\VendeurHorizonDemoSeeder::class)->run(app(\App\Domain\Catalog\PackVersionValidator::class), app(\App\Domain\Audit\AuditLogger::class)))
            ->toThrow(RuntimeException::class, 'simulation');
        expect(PackPlan::query()->where('code', 'TST-VND-HORIZON')->exists())->toBeFalse();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('runs the fake-provider demonstration chain from subscription through seller sale and Accounting validation', function () {
    $this->artisan('db:seed', ['--class' => \Database\Seeders\VendeurHorizonDemoSeeder::class])->assertSuccessful();
    configureCectPernum();
    $person = User::factory()->create();
    $person->pernumIdentities()->create(['pernum' => '1001000777', 'kind' => 'personal', 'status' => 'declared']);
    \App\Domain\Payout\Models\PayoutDestination::query()->create([
        'user_id' => $person->id, 'method' => 'TMONEY', 'channel' => 'mobile_money',
        'country' => 'TG', 'phone' => '+22890123777',
    ]);

    $offer = PackPlan::query()->where('code', 'TST-VND-HORIZON')->sole();
    $pack = subscribeAndPay($person, $offer);
    expect($pack->account()->firstOrFail()->available)->toBe('10000.000000');

    $sale = $this->withToken(tokenFor($person))
        ->postJson('/api/v1/me/exchange-requests', ['user_pack_id' => $pack->id, 'quantity' => '3000'], ['Idempotency-Key' => (string) \Illuminate\Support\Str::ulid()])
        ->assertCreated()
        ->assertJsonPath('data.status', 'awaiting_transfer')
        ->assertJsonPath('data.quantity_requested', '3000.000000')
        ->assertJsonPath('data.rate_xof_per_yem', 510)
        ->assertJsonPath('data.estimated_amount_xof', 1530000);
    $exchangeId = $sale->json('data.id');
    expect($pack->account()->firstOrFail()->available)->toBe('7000.000000')
        ->and($pack->account()->firstOrFail()->reserved)->toBe('3000.000000');

    $this->withToken(tokenFor($person))
        ->postJson("/api/v1/me/exchange-requests/{$exchangeId}/declare-sent", ['transfer_reference' => 'SIMULATION-TST-001'])
        ->assertOk()->assertJsonPath('data.yem_confirmed', false);
    expect($pack->account()->firstOrFail()->reserved)->toBe('3000.000000');

    $accounting = User::factory()->staff(\App\Domain\Access\PermissionCatalog::ACCOUNTING)->create();
    $this->withToken(tokenFor($accounting, withStepUp: true))
        ->postJson("/api/v1/validations/{$exchangeId}/confirm", [
            'quantity_received' => '2500', 'external_reference' => 'SIMULATION-CECT-RECEIPT-001',
        ], ['Idempotency-Key' => (string) \Illuminate\Support\Str::ulid()])
        ->assertOk()
        ->assertJsonPath('data.amount_due_xof', 1275000)
        ->assertJsonPath('data.yem_confirmed', true);
    expect($pack->account()->firstOrFail()->available)->toBe('7500.000000')
        ->and($pack->account()->firstOrFail()->reserved)->toBe('0.000000')
        ->and($pack->account()->firstOrFail()->consumed)->toBe('2500.000000');
    expect(\App\Domain\Payout\Models\PayoutBatch::query()->count())->toBe(1)
        ->and(\App\Domain\Payout\Models\PayoutBatch::query()->sole()->total_amount)->toBe(1275000);
});
