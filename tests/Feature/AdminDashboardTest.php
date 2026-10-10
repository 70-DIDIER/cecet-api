<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Exchange\Actions\SellerExchangeService;
use App\Domain\Exchange\Actions\ValidationDesk;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Payout\Models\PayoutBatch;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $this->validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    $this->accounting = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();

    $this->member = User::factory()->status(UserStatus::PendingPayment)->create(['first_name' => 'Yendoukoa', 'last_name' => 'NAKORE']);
    $this->member->pernumIdentities()->create(['pernum' => '1001043953', 'kind' => 'personal', 'status' => 'declared']);

    $attemptId = $this->withToken(tokenFor($this->member))
        ->postJson('/api/v1/me/membership/payments', ['method' => 'CASH'], ['Idempotency-Key' => (string) Str::ulid()])
        ->json('data.id');
    $this->withToken(tokenFor($this->validator, withStepUp: true))
        ->postJson("/api/v1/validations/payments/{$attemptId}/approve", ['received_amount' => 25000, 'reference' => 'Reçu 1'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    subscribeAndPay($this->member->refresh(), makePlan(PackFamily::Vendeur, 'V1'));
});

describe('dashboard', function () {
    it('gives finance and administrators the figures of the period', function () {
        $data = $this->withToken(tokenFor($this->admin))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');

        expect($data['summary']['kpis']['confirmed_payments'])->toBe(2)
            ->and($data['series']['days'])->toHaveCount(30)
            ->and($data['recent_payments'])->not->toBeEmpty()
            ->and($data['recent_payments'][0])->toHaveKeys(['id', 'label', 'member', 'method', 'amount_xof', 'date'])
            ->and($data['overview'])->not->toBeNull()
            ->and($data['pending_validations'])->toHaveKeys(['payments', 'yem', 'merchant'])
            ->and($data['period'])->toHaveKeys(['from', 'to']);
    });

    it('accepts a custom period and compares it with the previous one', function () {
        $today = now()->format('Y-m-d');

        $response = $this->withToken(tokenFor($this->admin))->getJson("/api/v1/admin/dashboard?from={$today}&to={$today}")->assertOk();

        expect($response->json('data.series.days'))->toBe([$today])
            ->and($response->json('data.trend'))->toBeNull();
    });

    it('only returns the blocks the account may see', function () {
        $data = $this->withToken(tokenFor($this->validator))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');

        expect($data['summary'])->toBeNull()
            ->and($data['series'])->toBeNull()
            ->and($data['recent_payments'])->toBeNull()
            ->and($data['pending_validations']['payments'])->toBeInt();
    });

    it('is closed to members and to anonymous callers', function () {
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
        $this->withToken(tokenFor($this->member))->getJson('/api/v1/admin/dashboard')->assertForbidden();
    });

    it('rejects an invalid period', function () {
        $this->withToken(tokenFor($this->admin))->getJson('/api/v1/admin/dashboard?from=2026-02-10&to=2026-02-01')->assertUnprocessable();
    });
});

describe('member overview', function () {
    it('returns the whole file of a member in one call', function () {
        $data = $this->withToken(tokenFor($this->admin))->getJson("/api/v1/admin/members/{$this->member->id}/overview")->assertOk()->json('data');

        expect($data['member']['id'])->toBe($this->member->id)
            ->and($data['member']['pernums'])->toHaveCount(1)
            ->and($data['membership']['status'])->toBe('active')
            ->and($data['packs'])->toHaveCount(1)
            ->and($data['packs'][0]['family'])->toBe('VENDEUR')
            ->and($data['packs'][0])->toHaveKey('merchant_account')
            ->and($data['payments'])->toHaveCount(2)
            ->and($data['exchanges'])->toBe([])
            ->and($data['merchant_payments'])->toBe([])
            ->and($data['audit_events'])->toBeArray();
    });

    it('lists the exchanges of the member', function () {
        [$member, $pack] = readySeller(['quota_per_period' => '5000'], 'TMONEY');
        configureCectPernum();
        $exchange = app(SellerExchangeService::class)->create($member, $pack->load('version'), '100');

        $data = $this->withToken(tokenFor($this->admin))->getJson("/api/v1/admin/members/{$member->id}/overview")->assertOk()->json('data');

        expect($data['exchanges'])->toHaveCount(1)
            ->and($data['exchanges'][0]['reference'])->toBe($exchange->reference);
    });

    it('refuses staff accounts and callers without the permission', function () {
        $this->withToken(tokenFor($this->admin))->getJson("/api/v1/admin/members/{$this->validator->id}/overview")->assertStatus(422);
        $this->withToken(tokenFor($this->member))->getJson("/api/v1/admin/members/{$this->member->id}/overview")->assertForbidden();
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $this->getJson("/api/v1/admin/members/{$this->member->id}/overview")->assertUnauthorized();
    });
});

describe('payout list', function () {
    it('filters by reference and summarises what is owed', function () {
        configureCectPernum();
        [$seller, $pack] = readySeller(['quota_per_period' => '5000'], 'TMONEY');
        $exchange = app(SellerExchangeService::class)->create($seller, $pack->load('version'), '1000');
        app(ValidationDesk::class)->confirm($this->validator, $exchange, '1000', 'YEM-TX', null);
        $batch = PayoutBatch::query()->where('payable_id', $exchange->id)->sole();

        $response = $this->withToken(tokenFor($this->accounting))->getJson('/api/v1/finance/payouts?search='.strtolower($batch->reference))->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('summary.owed_amount'))->toBe($batch->remaining_amount)
            ->and($response->json('summary'))->toHaveKeys(['needing_action', 'pending_settlements']);

        $this->withToken(tokenFor($this->accounting))->getJson('/api/v1/finance/payouts?search=PO-9999999')->assertOk()->assertJsonCount(0, 'data');
    });
});
