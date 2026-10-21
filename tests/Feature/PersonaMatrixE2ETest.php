<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Merchant\Models\MerchantAccount;
use App\Domain\Payment\Enums\ProviderPaymentStatus;
use App\Domain\Payout\Models\PayoutBatch;
use App\Domain\Payout\Models\PayoutDestination;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    if (\Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'cectapi_test') {
        throw new RuntimeException('SAFETY: persona scenarios must never run on CECT live.');
    }
    seedCatalogs();
    Mail::fake();
});

it('tests all CECT actor types in one verified purchase QR settlement cashback and accounting circuit', function () {
    configureCectPernum();
    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    $administrator = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $accounting = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();

    $seller = User::factory()->create(['email' => 'qa-seller@example.test']);
    $consumer = User::factory()->create(['email' => 'qa-consumer@example.test']);
    $personal = User::factory()->create(['email' => 'qa-personal@example.test']);
    $merchant = User::factory()->legalPerson()->create(['email' => 'qa-merchant@example.test']);
    $unpaid = User::factory()->status(UserStatus::PendingPayment)->create();
    $suspended = User::factory()->status(UserStatus::Suspended)->create();

    $seller->pernumIdentities()->create(['pernum' => 'QA-SELLER-0001', 'kind' => 'personal', 'status' => 'declared']);
    $consumer->pernumIdentities()->create(['pernum' => 'QA-CONSUMER-001', 'kind' => 'personal', 'status' => 'declared']);
    $merchant->pernumIdentities()->create(['pernum' => 'QA-MERCHANT-001', 'kind' => 'business', 'status' => 'verified']);
    PayoutDestination::query()->create([
        'user_id' => $merchant->id, 'method' => 'TMONEY', 'channel' => 'mobile_money',
        'country' => 'TG', 'phone' => '+22890112233',
    ]);

    // All four distinct pack families have a real published version in the test DB.
    $vendorPlan = makePlan(PackFamily::Vendeur, 'QA-V1');
    $consumerPlan = makePlan(PackFamily::Consommateur, 'QA-C1');
    $personnelPlan = makePlan(PackFamily::Personnel, 'QA-P1', personnelTerms([
        'price_xof' => 0, 'rules' => ['min_topup_xof' => 5000],
    ]));
    $merchantPlan = makePlan(PackFamily::Marchand, 'QA-M1', periodicTerms([
        'eligible_person_types' => ['legal'],
    ]));

    // Test role segregation and person-type eligibility before financial operations.
    $this->withToken(tokenFor($consumer))->getJson('/api/v1/admin/members')->assertForbidden();
    $this->withToken(tokenFor($consumer))->postJson('/api/v1/me/packs', ['plan_id' => $merchantPlan->id],
        ['Idempotency-Key' => (string) Str::ulid()])->assertStatus(422);
    $this->withToken(tokenFor($administrator))->getJson('/api/v1/admin/members')->assertOk();
    $this->withToken(tokenFor($accounting))->getJson('/api/v1/validations/queue')->assertOk();
    $this->withToken(tokenFor($accounting))->getJson('/api/v1/admin/staff')->assertForbidden();
    $this->withToken(tokenFor($superadmin))->getJson('/api/v1/admin/settings')->assertOk();

    $sellerPack = subscribeAndPay($seller, $vendorPlan);
    $consumerPack = subscribeAndPay($consumer, $consumerPlan);
    $personalPack = subscribeAndPay($personal, $personnelPlan);
    $merchantPack = subscribeAndPay($merchant, $merchantPlan);

    expect(UserPack::query()->where('status', 'active')->count())->toBe(4)
        ->and($sellerPack->account()->first()->available)->toBe('125.000000')
        ->and($consumerPack->account()->first()->available)->toBe('125.000000')
        ->and($personalPack->account()->first()->available)->toBe('0.000000');

    // Cashback belongs to personal/consumer plans, never Vendor or Merchant rights.
    app(SettingsService::class)->set('cashback.rules', [
        'enabled' => true, 'rate_bps' => 500, 'max_yem' => null,
        'families' => ['CONSOMMATEUR', 'PERSONNEL'], 'rounding' => 'down',
    ], 'Recette persona : bonus cinq pour cent');

    $topup = $this->withToken(tokenFor($personal))->postJson(
        "/api/v1/me/packs/{$personalPack->id}/topups", ['amount_xof' => 6500],
        ['Idempotency-Key' => (string) Str::ulid()]
    )->assertCreated()->assertJsonPath('data.topup.yem_base', '10.000000')
        ->assertJsonPath('data.topup.yem_cashback', '0.500000');
    settleFakeAttempt($topup->json('data.payment.id'), ProviderPaymentStatus::Approved)->assertOk();
    expect($personalPack->account()->first()->available)->toBe('10.500000');

    $qr = $this->withToken(tokenFor($merchant))->postJson('/api/v1/merchant/qr-codes',
        ['user_pack_id' => $merchantPack->id, 'outlet_label' => 'Boutique QA'],
        ['Idempotency-Key' => (string) Str::ulid()]
    )->assertCreated()->json('data.code');

    $payment = $this->withToken(tokenFor($consumer))->postJson('/api/v1/merchant-payments/intents',
        ['token' => $qr, 'user_pack_id' => $consumerPack->id, 'quantity' => '40'],
        ['Idempotency-Key' => (string) Str::ulid()]
    )->assertCreated()->assertJsonPath('data.status', 'reserved')->json('data');

    expect($consumerPack->account()->first()->reserved)->toBe('40.000000');
    $this->withToken(tokenFor($consumer))->postJson("/api/v1/me/merchant-payments/{$payment['id']}/declare-sent",
        ['reference' => 'QA-FOUNDATION-TRANSFER'])->assertOk();
    // A declaration is never sufficient to credit merchant money.
    expect(MerchantAccount::query()->count())->toBe(0);
    $this->withToken(tokenFor($merchant))->postJson("/api/v1/merchant/payments/{$payment['id']}/confirm", [],
        ['Idempotency-Key' => (string) Str::ulid()]
    )->assertOk()->assertJsonPath('data.status', 'confirmed');
    expect(MerchantAccount::query()->findOrFail($merchantPack->id)->eligible)->toBe('40.000000');

    $redemption = $this->withToken(tokenFor($merchant))->postJson('/api/v1/merchant/redemptions',
        ['user_pack_id' => $merchantPack->id, 'quantity' => '30'],
        ['Idempotency-Key' => (string) Str::ulid()]
    )->assertCreated()->assertJsonPath('data.estimated_amount_xof', 18300)->json('data');

    $this->withToken(tokenFor($merchant))->postJson("/api/v1/me/exchange-requests/{$redemption['id']}/declare-sent",
        ['transfer_reference' => 'QA-FOUNDATION-REDEMPTION'])->assertOk();
    $this->withToken(tokenFor($accounting, withStepUp: true))->postJson(
        "/api/v1/validations/{$redemption['id']}/confirm",
        ['quantity_received' => '25', 'external_reference' => 'QA-FOUNDATION-REDEMPTION'],
        ['Idempotency-Key' => (string) Str::ulid()]
    )->assertOk()->assertJsonPath('data.amount_due_xof', 15250);

    expect(PayoutBatch::query()->sole()->total_amount)->toBe(15250)
        ->and(MerchantAccount::query()->findOrFail($merchantPack->id)->redeemed)->toBe('25.000000')
        ->and($sellerPack->account()->first()->available)->toBe('125.000000');

    // Rejected transactions and ineligible memberships must never debit a real ledger.
    $this->withToken(tokenFor($unpaid))->postJson('/api/v1/me/packs', ['plan_id' => $vendorPlan->id],
        ['Idempotency-Key' => (string) Str::ulid()])->assertForbidden();
    $this->withToken(tokenFor($suspended))->getJson('/api/v1/me')->assertUnauthorized();

    $this->artisan('ledger:verify')->assertSuccessful();
    $this->artisan('audit:verify')->assertSuccessful();
});

it('creates and isolates 800 synthetic members across PF, PM and every membership status', function () {
    $states = [
        UserStatus::Active, UserStatus::PendingEmailVerification, UserStatus::PendingPayment,
        UserStatus::PendingActivation, UserStatus::Suspended, UserStatus::Closed,
    ];

    $ids = [];
    foreach (['natural', 'legal'] as $type) {
        for ($i = 0; $i < 400; $i++) {
            $factory = $type === 'legal' ? User::factory()->legalPerson() : User::factory();
            $user = $factory->status($states[$i % count($states)])->create([
                'email' => sprintf('qa-load-%s-%04d@example.test', $type, $i),
            ]);
            $ids[] = $user->id;
        }
    }

    expect(User::query()->whereIn('id', $ids)->count())->toBe(800)
        ->and(User::query()->whereIn('id', $ids)->where('person_type', 'natural')->count())->toBe(400)
        ->and(User::query()->whereIn('id', $ids)->where('person_type', 'legal')->count())->toBe(400)
        ->and(User::query()->whereIn('id', $ids)->distinct('email')->count('email'))->toBe(800);

    $active = User::query()->whereIn('id', $ids)->where('status', 'active')->firstOrFail();
    $blocked = User::query()->whereIn('id', $ids)->where('status', 'suspended')->firstOrFail();

    $this->withToken(tokenFor($active))->getJson('/api/v1/me')->assertOk();
    $this->withToken(tokenFor($blocked))->getJson('/api/v1/me')->assertUnauthorized();
    $this->artisan('ledger:verify')->assertSuccessful();
});

it('registers twelve new PF/PM identities with separate emails and Pernum, without sending real mail', function () {
    for ($i = 1; $i <= 12; $i++) {
        $legal = $i > 6;
        $data = [
            'person_type' => $legal ? 'legal' : 'natural',
            'first_name' => 'QA',
            'last_name' => sprintf('Persona %d', $i),
            'email' => sprintf('qa-%s-registration-%02d@example.test', $legal ? 'legal' : 'natural', $i),
            'phone' => sprintf('+2289%07d', $i),
            'pernum' => sprintf('PN-QA-%04d', $i),
            'password' => 'Solide2026Pass',
            'password_confirmation' => 'Solide2026Pass',
            'commitment_accepted' => true,
            'signed_place' => 'Lomé',
            'signed_on' => now()->toDateString(),
        ];
        if ($legal) {
            $data['legal_name'] = sprintf('Société QA %d SARL', $i);
            $data['legal_form'] = 'SARL';
        }
        // Registration is rate-limited by client IP: each identity in this
        // population test represents a distinct simulated device/network.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.(10 + $i)])
            ->postJson('/api/v1/auth/register', $data,
                ['Idempotency-Key' => (string) Str::ulid()])
            ->assertCreated()
            ->assertJsonPath('data.user.person_type', $legal ? 'legal' : 'natural')
            ->assertJsonPath('data.user.status', 'pending_email_verification');
    }
    expect(User::query()->where('kind', 'member')->count())->toBe(12);
    $this->artisan('audit:verify')->assertSuccessful();
});
