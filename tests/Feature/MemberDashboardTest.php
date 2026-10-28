<?php

use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
});

it('never exposes Vendeur sale capacity as spendable YEM on the member dashboard', function () {
    $member = User::factory()->create();
    $seller = subscribeAndPay($member, makePlan(PackFamily::Vendeur, 'V1'));

    $this->withToken(tokenFor($member))->getJson('/api/v1/me/dashboard')
        ->assertOk()
        ->assertJsonPath('data.yem.balance', '0.000000')
        ->assertJsonPath('data.yem.total_credited', '0.000000');

    $consumer = subscribeAndPay($member, makePlan(PackFamily::Consommateur, 'C1'));
    $consumerAvailable = $consumer->account()->firstOrFail()->available;
    $sellerAvailable = $seller->account()->firstOrFail()->available;

    expect($sellerAvailable)->not->toBe('0.000000');

    $response = $this->withToken(tokenFor($member))->getJson('/api/v1/me/dashboard')->assertOk();
    $response->assertJsonPath('data.yem.balance', $consumerAvailable);
    expect((float) $response->json('data.yem.balance'))->toBeLessThan((float) $consumerAvailable + (float) $sellerAvailable);
});

it('summarises the member area: profile, YEM balance, active packs and latest movements', function () {
    $member = User::factory()->create(['city' => 'Lomé']);
    $member->pernumIdentities()->create(['pernum' => '1001043953', 'kind' => 'personal', 'status' => 'declared']);

    $consumer = subscribeAndPay($member, makePlan(PackFamily::Consommateur, 'C1', [
        'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 12],
        'cumulative' => true,
        'end_of_term' => 'expire',
        'rules' => ['reference_value_xof' => 300000],
        'reason' => 'Grille de l\'ancien site',
    ]));

    $this->withToken(tokenFor($member))->getJson('/api/v1/me/dashboard')
        ->assertOk()
        ->assertJsonPath('data.profile.pernum', '1001043953')
        ->assertJsonPath('data.profile.city', 'Lomé')
        ->assertJsonPath('data.yem.balance', '38.461538')
        ->assertJsonPath('data.yem.total_credited', '38.461538')
        ->assertJsonPath('data.yem.total_spent', '0.000000')
        ->assertJsonPath('data.active_packs.0.id', $consumer->id)
        ->assertJsonPath('data.active_packs.0.code', 'C1')
        ->assertJsonPath('data.active_packs.0.price_xof', 300000)
        ->assertJsonPath('data.active_packs.0.cashback.rate_bps', 0)
        ->assertJsonPath('data.recent_movements.0.label', 'Crédit période 1 — C1')
        ->assertJsonPath('data.recent_movements.0.direction', 'credit')
        ->assertJsonPath('data.recent_movements.0.quantity', '38.461538')
        ->assertJsonPath('data.payments_awaiting_action', []);
});

it('shows a membership payment still awaiting validation', function () {
    $member = User::factory()->status(UserStatus::PendingPayment)->create();

    $this->withToken(tokenFor($member))
        ->postJson('/api/v1/me/membership/payments', ['method' => 'CASH'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated();

    $this->withToken(tokenFor($member))->getJson('/api/v1/me/dashboard')
        ->assertOk()
        ->assertJsonPath('data.membership.paid', false)
        ->assertJsonPath('data.active_packs', [])
        ->assertJsonPath('data.yem.balance', '0.000000')
        ->assertJsonPath('data.payments_awaiting_action.0.method', 'CASH')
        ->assertJsonPath('data.payments_awaiting_action.0.mode', 'manual');
});

it('requires authentication', function () {
    $this->getJson('/api/v1/me/dashboard')->assertUnauthorized();
});

it('gives the periods of each pack, the monthly activity and the open operations in the same call', function () {
    $member = User::factory()->create();
    $member->pernumIdentities()->create(['pernum' => '1001043953', 'kind' => 'personal', 'status' => 'declared']);

    subscribeAndPay($member, makePlan(PackFamily::Consommateur, 'C1', [
        'period' => ['unit' => 'month', 'every' => 1, 'cycles' => 12],
        'cumulative' => true,
        'end_of_term' => 'expire',
        'rules' => ['reference_value_xof' => 300000],
        'reason' => 'Grille de l\'ancien site',
    ]));

    $data = $this->withToken(tokenFor($member))->getJson('/api/v1/me/dashboard')->assertOk()->json('data');

    expect($data['active_packs'][0]['period']['cycles'])->toBe(12)
        ->and($data['active_packs'][0]['period'])->toHaveKeys(['number', 'starts_at', 'ends_at', 'next_credit_at'])
        ->and($data['active_packs'][0]['reserved'])->toBe('0.000000')
        ->and($data['monthly_activity'])->toHaveCount(6)
        ->and($data['monthly_activity'][5]['month'])->toBe(now()->format('Y-m'))
        ->and($data['monthly_activity'][5]['credit'])->toBe('38.461538')
        ->and($data['operations'])->toBe([]);
});

it('keeps a freshly subscribed Pack Marchand out of the YEM balance: its quota is a redemption ceiling, its collected balance starts at zero', function () {
    $merchant = User::factory()->legalPerson()->create();
    $pack = subscribeAndPay($merchant, makePlan(PackFamily::Marchand, 'BRONZE', periodicTerms(['eligible_person_types' => ['legal']])));

    $this->withToken(tokenFor($merchant))->getJson('/api/v1/me/dashboard')
        ->assertOk()
        ->assertJsonPath('data.yem.balance', '0.000000')
        ->assertJsonPath('data.yem.reserved', '0.000000')
        ->assertJsonPath('data.yem.total_credited', '0.000000')
        ->assertJsonPath('data.active_packs.0.id', $pack->id)
        ->assertJsonPath('data.active_packs.0.merchant_balance.eligible', '0.000000')
        ->assertJsonPath('data.active_packs.0.merchant_balance.redeemed', '0.000000')
        ->assertJsonPath('data.recent_movements', []);
});
