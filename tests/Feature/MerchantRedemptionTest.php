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

    $this->validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
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

it('redeems collected YEM: reservation, transfer to the CECT, Validator confirmation, payout', function () {
    $exchange = redeem($this->merchant, $this->merchantPack, '50')
        ->assertCreated()
        ->assertJsonPath('data.kind', 'merchant_redemption')
        ->assertJsonPath('data.transfer_instructions.send_to_pernum', '1000000001')
        ->assertJsonPath('data.transfer_instructions.from_pernum', '1001292934')
        ->assertJsonPath('data.rate_xof_per_yem', 500)
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
        ->assertJsonPath('data.amount_due_xof', 22500);

    // 45 redeemed, the 5 not received go back to the eligible balance and to the pack quota.
    expect(merchantBalance($this->merchantPack))->toBe(['15.000000', '0.000000', '45.000000'])
        ->and($this->merchantPack->account()->first()->consumed)->toBe('45.000000')
        ->and(PayoutBatch::query()->sole()->total_amount)->toBe(22500);

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
        ->assertJsonPath('data.yem_activity.redeemed_by_merchants_xof', 20000)
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
        ->assertJsonPath('data.payouts.remaining_due_xof', 20000)
        ->assertJsonPath('data.payouts.remaining_due_batches', 1)
        ->assertJsonPath('data.payouts.paid_by_provider_xof', 0);
});
