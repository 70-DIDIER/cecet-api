<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Merchant\Actions\MerchantPaymentService;
use App\Domain\Merchant\Enums\MerchantPaymentStatus;
use App\Domain\Merchant\Models\MerchantAccount;
use App\Domain\Merchant\Models\MerchantLedgerEntry;
use App\Domain\Merchant\Models\MerchantPayment;
use App\Domain\Quota\QuotaLedger;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();

    $this->merchant = User::factory()->legalPerson()->create();
    $this->merchant->pernumIdentities()->create(['pernum' => '1001292934', 'kind' => 'business', 'status' => 'verified']);
    $this->merchantPack = subscribeAndPay($this->merchant, makePlan(PackFamily::Marchand, 'BRONZE', periodicTerms(['eligible_person_types' => ['legal']])));

    $this->payer = User::factory()->create();
    $this->payer->pernumIdentities()->create(['pernum' => '1001043953', 'kind' => 'personal', 'status' => 'declared']);
    $this->consumerPack = subscribeAndPay($this->payer, makePlan(PackFamily::Consommateur, 'C1'));

    $this->token = $this->withToken(tokenFor($this->merchant))
        ->postJson('/api/v1/merchant/qr-codes', ['user_pack_id' => $this->merchantPack->id, 'outlet_label' => 'Boutique Bè'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()
        ->json('data.code');

    $this->validator = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
});

function payMerchant(User $payer, UserPack $pack, string $token, string $quantity): TestResponse
{
    return test()->withToken(tokenFor($payer))
        ->postJson('/api/v1/merchant-payments/intents', ['token' => $token, 'user_pack_id' => $pack->id, 'quantity' => $quantity], ['Idempotency-Key' => (string) Str::ulid()]);
}

function quotaOf(UserPack $pack): array
{
    $account = $pack->account()->firstOrFail();

    return [$account->available, $account->reserved, $account->consumed];
}

it('resolves a QR on the server and never trusts its content', function () {
    $this->withToken(tokenFor($this->payer))->getJson("/api/v1/merchant-payments/qr/{$this->token}")
        ->assertOk()
        ->assertJsonPath('data.pernum', '1001292934')
        ->assertJsonPath('data.outlet_label', 'Boutique Bè')
        ->assertJsonPath('data.merchant.id', $this->merchant->id);

    $this->withToken(tokenFor($this->payer))->getJson('/api/v1/merchant-payments/qr/1001292934')->assertNotFound()->assertJsonPath('code', 'MERCHANT_QR_INVALID');
});

it('runs the whole payment: reservation at the scan, declaration, confirmation by the merchant', function () {
    $payment = payMerchant($this->payer, $this->consumerPack, $this->token, '40')
        ->assertCreated()
        ->assertJsonPath('data.status', 'reserved')
        ->assertJsonPath('data.merchant_pernum', '1001292934')
        ->assertJsonPath('data.confirmation_mode', 'merchant')
        ->json('data');

    expect($payment['reference'])->toStartWith('MP-')
        ->and(quotaOf($this->consumerPack))->toBe(['85.000000', '40.000000', '0.000000']);

    $this->withToken(tokenFor($this->payer))->postJson("/api/v1/me/merchant-payments/{$payment['id']}/declare-sent", ['reference' => 'YEMPay 7781'])
        ->assertOk()
        ->assertJsonPath('data.status', 'declared_sent');

    // Declaring changes nothing to the balances.
    expect(quotaOf($this->consumerPack))->toBe(['85.000000', '40.000000', '0.000000'])
        ->and(MerchantAccount::query()->find($this->merchantPack->id))->toBeNull();

    $this->withToken(tokenFor($this->merchant))->getJson('/api/v1/merchant/payments?status=declared_sent')
        ->assertJsonPath('data.0.payer.pernum', '1001043953')
        ->assertJsonPath('data.0.transfer_reference', 'YEMPay 7781');

    $this->withToken(tokenFor($this->merchant))->postJson("/api/v1/merchant/payments/{$payment['id']}/confirm", [], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    expect(quotaOf($this->consumerPack))->toBe(['85.000000', '0.000000', '40.000000'])
        ->and(MerchantAccount::query()->findOrFail($this->merchantPack->id)->eligible)->toBe('40.000000')
        ->and(AuditEvent::query()->where('action', 'merchant_payments.confirmed_by_merchant')->sole()->actor_id)->toBe($this->merchant->id);

    $this->withToken(tokenFor($this->merchant))->getJson('/api/v1/merchant/balance')
        ->assertJsonPath('data.packs.0.eligible', '40.000000')
        ->assertJsonPath('data.recent_movements.0.type', 'credit');

    // Confirming twice changes nothing.
    app(MerchantPaymentService::class)->confirmByMerchant($this->merchant, MerchantPayment::query()->findOrFail($payment['id']));
    expect(MerchantLedgerEntry::query()->count())->toBe(1);
    $this->artisan('ledger:verify')->expectsOutputToContain('1 compte(s) marchand')->assertSuccessful();
});

it('refuses more than the available quota, a Vendeur pack, and paying oneself', function () {
    payMerchant($this->payer, $this->consumerPack, $this->token, '125.000001')->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_QUOTA');

    $vendeur = subscribeAndPay($this->payer, makePlan(PackFamily::Vendeur, 'V1'));
    payMerchant($this->payer, $vendeur, $this->token, '1')->assertStatus(422)->assertJsonPath('code', 'PACK_NOT_ELIGIBLE_FOR_MERCHANT_PAYMENT');

    // The merchant is a PM. By the current CECT policy a PM cannot subscribe to a
    // Consommateur pack at all, so the old self-payment fixture is now invalid.
    $consumer = makePlan(PackFamily::Consommateur, 'C2');
    $this->withToken(tokenFor($this->merchant))
        ->postJson('/api/v1/me/packs', ['plan_id' => $consumer->id], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PACK_NOT_ELIGIBLE');

    $this->withToken(tokenFor($this->payer))->postJson('/api/v1/merchant-payments/intents', ['token' => $this->token, 'user_pack_id' => $this->merchantPack->id, 'quantity' => '1'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertNotFound();
});

it('pays with the balance of a Personnel pack', function () {
    $perso = subscribeAndPay($this->payer, makePlan(PackFamily::Personnel, 'PERSO', personnelTerms(['price_xof' => 0])));
    app(QuotaLedger::class)->credit($perso, '9.049773', 'test:topup');

    payMerchant($this->payer, $perso, $this->token, '9.049773')->assertCreated();
    payMerchant($this->payer, $perso, $this->token, '0.000001')->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_QUOTA');
});

it('gives the quota back on cancellation and on expiry, never after the declaration', function () {
    $canceled = payMerchant($this->payer, $this->consumerPack, $this->token, '10')->json('data.id');
    $this->withToken(tokenFor($this->payer))->postJson("/api/v1/me/merchant-payments/{$canceled}/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');

    $declared = payMerchant($this->payer, $this->consumerPack, $this->token, '20')->json('data.id');
    $this->withToken(tokenFor($this->payer))->postJson("/api/v1/me/merchant-payments/{$declared}/declare-sent")->assertOk();
    $this->withToken(tokenFor($this->payer))->postJson("/api/v1/me/merchant-payments/{$declared}/cancel")->assertStatus(409)->assertJsonPath('code', 'MERCHANT_PAYMENT_NOT_CANCELABLE');

    $forgotten = payMerchant($this->payer, $this->consumerPack, $this->token, '30')->json('data.id');
    $this->travel(61)->minutes();
    $this->artisan('merchant-payments:expire')->assertSuccessful();

    expect(MerchantPayment::query()->findOrFail($forgotten)->status)->toBe(MerchantPaymentStatus::Expired)
        ->and(MerchantPayment::query()->findOrFail($declared)->status)->toBe(MerchantPaymentStatus::DeclaredSent)
        ->and(quotaOf($this->consumerPack))->toBe(['105.000000', '20.000000', '0.000000']);
});

it('lets a Validator arbitrate a payment the merchant did not receive', function () {
    $id = payMerchant($this->payer, $this->consumerPack, $this->token, '15')->json('data.id');
    $this->withToken(tokenFor($this->payer))->postJson("/api/v1/me/merchant-payments/{$id}/declare-sent", ['reference' => 'YEMPay 1'])->assertOk();

    $this->withToken(tokenFor($this->merchant))->postJson("/api/v1/merchant/payments/{$id}/report-not-received", ['reason' => 'Rien reçu sur notre Pernum'])
        ->assertOk()
        ->assertJsonPath('data.status', 'disputed');

    $this->withToken(tokenFor($this->validator))->getJson('/api/v1/validations/queue')
        ->assertJsonPath('data.0.kind', 'merchant_payment')
        ->assertJsonPath('data.0.status', 'disputed')
        ->assertJsonPath('data.0.quantity', '15.000000');

    // Once disputed, the merchant can no longer decide alone.
    $this->withToken(tokenFor($this->merchant))->postJson("/api/v1/merchant/payments/{$id}/confirm", [], ['Idempotency-Key' => (string) Str::ulid()])->assertStatus(409);

    $this->withToken(tokenFor($this->validator, withStepUp: true))->postJson("/api/v1/validations/merchant-payments/{$id}/reject", ['reason' => 'Aucun transfert trouvé dans YEMPay'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    expect(quotaOf($this->consumerPack))->toBe(['125.000000', '0.000000', '0.000000']);
});

it('reserves the confirmation to Validators when so configured', function () {
    app(SettingsService::class)->set('merchant_payment.confirmation_mode', 'validator', 'Décision du CA : confirmation par la CECT');

    $id = payMerchant($this->payer, $this->consumerPack, $this->token, '12.5')->assertJsonPath('data.confirmation_mode', 'validator')->json('data.id');
    $this->withToken(tokenFor($this->payer))->postJson("/api/v1/me/merchant-payments/{$id}/declare-sent")->assertOk();

    $this->withToken(tokenFor($this->merchant))->postJson("/api/v1/merchant/payments/{$id}/confirm", [], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertForbidden()
        ->assertJsonPath('code', 'MERCHANT_PAYMENT_VALIDATOR_CONFIRMATION');

    $this->withToken(tokenFor($this->validator))->getJson('/api/v1/validations/queue')->assertJsonPath('data.0.status', 'pending');

    $this->withToken(tokenFor($this->validator))->postJson("/api/v1/validations/merchant-payments/{$id}/confirm", [], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertForbidden()
        ->assertJsonPath('code', 'STEP_UP_REQUIRED');

    $this->withToken(tokenFor($this->validator, withStepUp: true))->postJson("/api/v1/validations/merchant-payments/{$id}/confirm", [], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    expect(MerchantAccount::query()->findOrFail($this->merchantPack->id)->eligible)->toBe('12.500000');
});

it('stops resolving a revoked or rotated QR', function () {
    $qrId = $this->withToken(tokenFor($this->merchant))->getJson('/api/v1/merchant/qr-codes')->json('data.0.id');

    $next = $this->withToken(tokenFor($this->merchant, withStepUp: true))->postJson("/api/v1/merchant/qr-codes/{$qrId}/rotate", ['reason' => 'QR photographié par un tiers'])
        ->assertCreated()
        ->assertJsonPath('data.version', 2)
        ->json('data');

    payMerchant($this->payer, $this->consumerPack, $this->token, '1')->assertNotFound();
    payMerchant($this->payer, $this->consumerPack, $next['code'], '1')->assertCreated();

    $this->withToken(tokenFor($this->merchant))->postJson("/api/v1/merchant/qr-codes/{$next['id']}/revoke", ['reason' => 'Fermeture du point de vente'])->assertOk()->assertJsonPath('data.code', null);
    payMerchant($this->payer, $this->consumerPack, $next['code'], '1')->assertNotFound();
});

it('refuses QR codes for anything but an active Pack Marchand, and payments to a merchant whose pack ended', function () {
    $this->withToken(tokenFor($this->payer))->postJson('/api/v1/merchant/qr-codes', ['user_pack_id' => $this->consumerPack->id], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertForbidden()
        ->assertJsonPath('code', 'MERCHANT_PACK_REQUIRED');

    $this->merchantPack->forceFill(['status' => 'expired'])->save();

    payMerchant($this->payer, $this->consumerPack, $this->token, '1')->assertStatus(409)->assertJsonPath('code', 'MERCHANT_UNAVAILABLE');
});

it('keeps payments private to their parties', function () {
    $id = payMerchant($this->payer, $this->consumerPack, $this->token, '5')->json('data.id');
    $stranger = User::factory()->create();

    $this->withToken(tokenFor($stranger))->getJson("/api/v1/me/merchant-payments/{$id}")->assertNotFound();
    $this->withToken(tokenFor($stranger))->postJson("/api/v1/merchant/payments/{$id}/confirm", [], ['Idempotency-Key' => (string) Str::ulid()])->assertNotFound();
    $this->withToken(tokenFor($this->payer))->postJson("/api/v1/merchant/payments/{$id}/confirm", [], ['Idempotency-Key' => (string) Str::ulid()])->assertNotFound();
});

it('protects the merchant ledger against edits and negative balances', function () {
    $id = payMerchant($this->payer, $this->consumerPack, $this->token, '5')->json('data.id');
    $this->withToken(tokenFor($this->merchant))->postJson("/api/v1/merchant/payments/{$id}/confirm", [], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

    expect(fn () => DB::table('merchant_ledger_entries')->update(['eligible_delta' => 500]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('merchant_accounts')->update(['eligible' => -1]))->toThrow(QueryException::class);
});

it('refuses a zero, negative or malformed quantity cleanly', function (mixed $quantity) {
    $this->withToken(tokenFor($this->payer))
        ->postJson('/api/v1/merchant-payments/intents', ['token' => $this->token, 'user_pack_id' => $this->consumerPack->id, 'quantity' => $quantity], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_QUANTITY');

    expect(MerchantPayment::query()->count())->toBe(0);
})->with(['zero' => '0', 'zero decimals' => '0.000000', 'negative' => '-5', 'text' => 'dix', 'too precise' => '1.1234567']);
