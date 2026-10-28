<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Merchant\MerchantLedger;
use App\Domain\Merchant\Models\MerchantAccount;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\Models\PayoutDestination;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    configureCectPernum();

    $this->merchant = User::factory()->legalPerson()->create();
    $this->merchant->pernumIdentities()->create(['pernum' => '1001292934', 'kind' => 'business', 'status' => 'verified']);
    PayoutDestination::query()->create(['user_id' => $this->merchant->id, 'method' => 'TMONEY', 'channel' => 'mobile_money', 'phone' => '+22890623423']);
    $this->merchantPack = subscribeAndPay($this->merchant, makePlan(PackFamily::Marchand, 'BRONZE', periodicTerms(['eligible_person_types' => ['legal']])));

    // A member pays the merchant 60 YEM, confirmed by the merchant.
    $payer = User::factory()->create();
    $payer->pernumIdentities()->create(['pernum' => '1001043953', 'kind' => 'personal', 'status' => 'declared']);
    $consumer = subscribeAndPay($payer, makePlan(PackFamily::Consommateur, 'C1'));
    $token = $this->withToken(tokenFor($this->merchant))
        ->postJson('/api/v1/merchant/qr-codes', ['user_pack_id' => $this->merchantPack->id], ['Idempotency-Key' => (string) Str::ulid()])
        ->json('data.code');
    $paymentId = $this->withToken(tokenFor($payer))
        ->postJson('/api/v1/merchant-payments/intents', ['token' => $token, 'user_pack_id' => $consumer->id, 'quantity' => '60'], ['Idempotency-Key' => (string) Str::ulid()])
        ->json('data.id');
    $this->withToken(tokenFor($this->merchant))->postJson("/api/v1/merchant/payments/{$paymentId}/confirm", [], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

    $this->validator = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
});

function redeem(User $merchant, UserPack $pack, string $quantity): TestResponse
{
    return test()->withToken(tokenFor($merchant))
        ->postJson('/api/v1/merchant/redemptions', ['user_pack_id' => $pack->id, 'quantity' => $quantity], ['Idempotency-Key' => (string) Str::ulid()]);
}

function merchantBalance(UserPack $pack): array
{
    $account = MerchantAccount::query()->findOrFail($pack->id);

    return [$account->eligible, $account->reserved, $account->redeemed];
}

it('redeems collected YEM: reservation, transfer to the CECT, Accounting confirmation, payout', function () {
    $exchange = redeem($this->merchant, $this->merchantPack, '50')
        ->assertCreated()
        ->assertJsonPath('data.kind', 'merchant_redemption')
        ->assertJsonPath('data.transfer_instructions.send_to_pernum', '1000000001')
        ->assertJsonPath('data.transfer_instructions.from_pernum', '1001292934')
        ->assertJsonPath('data.rate_xof_per_yem', 610)
        ->json('data');

    expect($exchange['reference'])->toStartWith('RD-')
        ->and(merchantBalance($this->merchantPack))->toBe(['10.000000', '50.000000', '0.000000'])
        ->and($this->merchantPack->account()->first()->reserved)->toBe('50.000000');

    $this->withToken(tokenFor($this->merchant))->postJson("/api/v1/me/exchange-requests/{$exchange['id']}/declare-sent", ['transfer_reference' => 'YEMPay 9'])->assertOk();

    $this->withToken(tokenFor($this->validator))->getJson('/api/v1/validations/queue')
        ->assertJsonPath('data.0.label', "Rééchange marchand {$exchange['reference']}");

    $this->withToken(tokenFor($this->validator, withStepUp: true))
        ->postJson("/api/v1/validations/{$exchange['id']}/confirm", ['quantity_received' => '45', 'external_reference' => 'YEMPay 9'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()
        ->assertJsonPath('data.amount_due_xof', 27450);

    // 45 redeemed, the 5 not received go back to the eligible balance and to the pack quota.
    expect(merchantBalance($this->merchantPack))->toBe(['15.000000', '0.000000', '45.000000'])
        ->and($this->merchantPack->account()->first()->consumed)->toBe('45.000000')
        ->and(PayoutBatch::query()->sole()->total_amount)->toBe(27450);

    $this->artisan('ledger:verify')->assertSuccessful();
});

it('freezes the configurable sale-minus-fee contract even after SuperAdmin changes rates and policy', function () {
    $settings = app(SettingsService::class);
    $settings->set('merchant_redemption.pricing', [
        'mode' => 'sale_minus_fee',
        'fee_bps' => 300,
        'fixed_fee_xof' => 75,
        'fee_rounding' => 'down',
    ], 'Barème de cession Marchand : 3 % et frais fixes');

    $request = redeem($this->merchant, $this->merchantPack, '50')
        ->assertCreated()
        ->assertJsonPath('data.rate_xof_per_yem', 650)
        ->assertJsonPath('data.estimated_amount_xof', 31450)
        ->assertJsonPath('data.merchant_pricing.estimated.gross_amount_xof', 32500)
        ->assertJsonPath('data.merchant_pricing.estimated.management_fee_xof', 1050)
        ->assertJsonPath('data.merchant_pricing.policy.mode', 'sale_minus_fee')
        ->assertJsonPath('data.merchant_pricing.policy.fee_bps', 300)
        ->json('data');

    // Future pricing changes must not rewrite the reserved member's quotation.
    $settings->set('rates.sale_xof_per_yem', 700, 'Nouveau cours de vente à venir');
    $settings->set('merchant_redemption.pricing', [
        'mode' => 'fixed', 'fee_bps' => 0, 'fixed_fee_xof' => 0, 'fee_rounding' => 'down',
    ], 'Retour au taux fixe pour les prochaines demandes');

    $this->withToken(tokenFor($this->validator, withStepUp: true))
        ->postJson("/api/v1/validations/{$request['id']}/confirm",
            ['quantity_received' => '45', 'external_reference' => 'CONFIRMED-YEM-45'],
            ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()
        ->assertJsonPath('data.amount_due_xof', 28298)
        ->assertJsonPath('data.merchant_pricing.confirmed.gross_amount_xof', 29250)
        ->assertJsonPath('data.merchant_pricing.confirmed.management_fee_xof', 952)
        ->assertJsonPath('data.merchant_pricing.confirmed.net_amount_xof', 28298);

    expect(PayoutBatch::query()->sole()->total_amount)->toBe(28298);

    $this->withToken(tokenFor(User::factory()->staff(PermissionCatalog::ADMIN)->create()))
        ->getJson('/api/v1/admin/reports/summary')
        ->assertOk()
        ->assertJsonPath('data.yem_activity.merchant_management_fees_xof', 952)
        ->assertJsonPath('data.yem_activity.redeemed_by_merchants_xof', 28298);

    $future = redeem($this->merchant, $this->merchantPack, '10')
        ->assertCreated()
        ->assertJsonPath('data.rate_xof_per_yem', 610)
        ->assertJsonPath('data.estimated_amount_xof', 6100)
        ->assertJsonPath('data.merchant_pricing.policy.mode', 'fixed')
        ->json('data');

    expect($request['id'])->not->toBe($future['id']);
    $this->artisan('ledger:verify')->assertSuccessful();
});

it('accepts rate changes from SuperAdmin and keeps all earlier merchant quotes unchanged', function () {
    $settings = app(SettingsService::class);
    $first = redeem($this->merchant, $this->merchantPack, '10')
        ->assertCreated()->assertJsonPath('data.estimated_amount_xof', 6100)->json('data');

    $settings->set('rates.transfer_xof_per_yem', 580, 'Cours Marchand rectifié par le SuperAdmin');

    $second = redeem($this->merchant, $this->merchantPack, '10')
        ->assertCreated()->assertJsonPath('data.estimated_amount_xof', 5800)->json('data');

    $this->withToken(tokenFor($this->merchant))->getJson("/api/v1/me/exchange-requests/{$first['id']}")
        ->assertOk()->assertJsonPath('data.estimated_amount_xof', 6100);
    $this->withToken(tokenFor($this->merchant))->getJson("/api/v1/me/exchange-requests/{$second['id']}")
        ->assertOk()->assertJsonPath('data.estimated_amount_xof', 5800);
});

it('blocks a merchant redemption when the configured fee leaves zero net FCFA', function () {
    app(SettingsService::class)->set('merchant_redemption.pricing', [
        'mode' => 'sale_minus_fee', 'fee_bps' => 300,
        'fixed_fee_xof' => 40000, 'fee_rounding' => 'down',
    ], 'Borne de test : refuser les versements nuls');

    redeem($this->merchant, $this->merchantPack, '50')
        ->assertStatus(422)
        ->assertJsonPath('code', 'MERCHANT_NET_PAYOUT_ZERO');

    expect(merchantBalance($this->merchantPack))->toBe(['60.000000', '0.000000', '0.000000'])
        ->and($this->merchantPack->account()->first()->reserved)->toBe('0.000000');
    $this->artisan('ledger:verify')->assertSuccessful();
});

it('refuses a tiny partial YEM confirmation whose fees exceed the value without consuming the reservation', function () {
    app(SettingsService::class)->set('merchant_redemption.pricing', [
        'mode' => 'sale_minus_fee', 'fee_bps' => 300,
        'fixed_fee_xof' => 75, 'fee_rounding' => 'down',
    ], 'Borne de test : confirmation partielle et frais fixes');
    $request = redeem($this->merchant, $this->merchantPack, '50')->assertCreated()->json('data');

    $this->withToken(tokenFor($this->validator, withStepUp: true))
        ->postJson("/api/v1/validations/{$request['id']}/confirm",
            ['quantity_received' => '0.1', 'external_reference' => 'YEM-PARTIAL-VERY-SMALL'],
            ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'MERCHANT_NET_PAYOUT_ZERO');

    expect(merchantBalance($this->merchantPack))->toBe(['10.000000', '50.000000', '0.000000'])
        ->and($this->merchantPack->account()->first()->reserved)->toBe('50.000000')
        ->and(PayoutBatch::query()->count())->toBe(0);
    $this->artisan('ledger:verify')->assertSuccessful();
});

it('cannot redeem more than the confirmed collections', function () {
    redeem($this->merchant, $this->merchantPack, '60.000001')->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_MERCHANT_BALANCE');
});

it('is capped by the periodic quota of the Pack Marchand unless configured otherwise', function () {
    $small = subscribeAndPay($this->merchant, makePlan(PackFamily::Marchand, 'ARGENT', periodicTerms(['quota_per_period' => '5', 'eligible_person_types' => ['legal']])));
    app(MerchantLedger::class)->credit($small, '20', 'test:collections');

    redeem($this->merchant, $small, '6')->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_QUOTA');

    app(SettingsService::class)->set('merchant_redemption.limit_by_pack_quota', false, 'Décision du CA : pas de plafond périodique');

    redeem($this->merchant, $small, '20')->assertCreated();
    expect($small->account()->first()->reserved)->toBe('0.000000');
});

it('gives the balance back on cancellation and expiry', function () {
    $id = redeem($this->merchant, $this->merchantPack, '30')->json('data.id');
    $this->withToken(tokenFor($this->merchant))->postJson("/api/v1/me/exchange-requests/{$id}/cancel")->assertOk();

    redeem($this->merchant, $this->merchantPack, '20');
    $this->travel(25)->hours();
    $this->artisan('exchanges:expire')->assertSuccessful();

    expect(merchantBalance($this->merchantPack))->toBe(['60.000000', '0.000000', '0.000000'])
        ->and($this->merchantPack->account()->first()->reserved)->toBe('0.000000');
});

it('reserves redemptions to the holder of an active Pack Marchand', function () {
    $member = User::factory()->create();
    $consumer = subscribeAndPay($member, makePlan(PackFamily::Consommateur, 'C9'));

    redeem($member, $consumer, '1')->assertForbidden()->assertJsonPath('code', 'MERCHANT_PACK_REQUIRED');
    redeem($member, $this->merchantPack, '1')->assertNotFound();
});

it('reports the YEM activity of the period', function () {
    $id = redeem($this->merchant, $this->merchantPack, '40')->json('data.id');
    $this->withToken(tokenFor($this->validator, withStepUp: true))
        ->postJson("/api/v1/validations/{$id}/confirm", ['quantity_received' => '40', 'external_reference' => 'YEMPay 10'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    $this->withToken(tokenFor(User::factory()->staff(PermissionCatalog::ADMIN)->create()))->getJson('/api/v1/admin/reports/summary')
        ->assertOk()
        ->assertJsonPath('data.yem_activity.paid_to_merchants', '60.000000')
        ->assertJsonPath('data.yem_activity.merchant_payments', 1)
        ->assertJsonPath('data.yem_activity.redeemed_by_merchants', '40.000000')
        ->assertJsonPath('data.yem_activity.redeemed_by_merchants_xof', 24400)
        ->assertJsonPath('data.yem_activity.active_merchants', 1);
});

it('lets the back-office follow merchant payments and correct a merchant balance, audited', function () {
    $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();

    $this->withToken(tokenFor($admin))->getJson("/api/v1/admin/merchant-payments?merchant_id={$this->merchant->id}&status=confirmed")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.quantity', '60.000000');

    $this->withToken(tokenFor($admin))->getJson("/api/v1/admin/merchant-accounts/{$this->merchantPack->id}")
        ->assertJsonPath('data.eligible', '60.000000')
        ->assertJsonPath('data.entries.0.type', 'credit');

    // Correcting a balance is reserved to the Superadmin (reserved permission), with a step-up.
    $this->withToken(tokenFor($admin, withStepUp: true))
        ->postJson("/api/v1/admin/merchant-accounts/{$this->merchantPack->id}/adjustments", ['quantity' => '-10', 'reason' => 'Doublon constaté au rapprochement'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertForbidden();

    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    $this->withToken(tokenFor($superadmin, withStepUp: true))
        ->postJson("/api/v1/admin/merchant-accounts/{$this->merchantPack->id}/adjustments", ['quantity' => '-10', 'reason' => 'Doublon constaté au rapprochement'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()
        ->assertJsonPath('data.eligible_after', '50.000000');

    $this->withToken(tokenFor($superadmin, withStepUp: true))
        ->postJson("/api/v1/admin/merchant-accounts/{$this->merchantPack->id}/adjustments", ['quantity' => '-50.000001', 'reason' => 'Correction excessive refusée'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INSUFFICIENT_MERCHANT_BALANCE');

    expect(AuditEvent::query()->where('action', 'merchant_balance.adjusted')->count())->toBe(1);
    $this->artisan('ledger:verify')->assertSuccessful();
});

it('exports the merchant payments of the period as CSV', function () {
    $csv = $this->withToken(tokenFor(User::factory()->staff(PermissionCatalog::ADMIN)->create(), withStepUp: true))
        ->get('/api/v1/admin/exports/merchant_payments')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Pernum marchand')->toContain(';1001292934;')->toContain(';60.000000;confirmed;merchant;');
});

it('reports the payouts owed and paid', function () {
    $id = redeem($this->merchant, $this->merchantPack, '40')->json('data.id');
    $this->withToken(tokenFor($this->validator, withStepUp: true))
        ->postJson("/api/v1/validations/{$id}/confirm", ['quantity_received' => '40', 'external_reference' => 'YEMPay 11'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    $this->withToken(tokenFor(User::factory()->staff(PermissionCatalog::ADMIN)->create()))->getJson('/api/v1/admin/reports/summary')
        ->assertJsonPath('data.payouts.remaining_due_xof', 24400)
        ->assertJsonPath('data.payouts.remaining_due_batches', 1)
        ->assertJsonPath('data.payouts.paid_by_provider_xof', 0);
});
