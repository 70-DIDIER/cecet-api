<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Payment\Enums\PaymentAttemptStatus;
use App\Domain\Payment\Models\PaymentAttempt;
use App\Domain\Settings\SettingsService;
use App\Domain\Subscription\Enums\UserPackStatus;
use App\Domain\Subscription\Models\UserPack;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->member = User::factory()->status(UserStatus::PendingPayment)->create();
    $this->member->pernumIdentities()->create(['pernum' => '1001043953', 'kind' => 'personal', 'status' => 'declared']);
    $this->validator = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
});

function payMembershipWith(User $member, ?string $method): TestResponse
{
    return test()->withToken(tokenFor($member))
        ->postJson('/api/v1/me/membership/payments', array_filter(['method' => $method]), ['Idempotency-Key' => (string) Str::ulid()]);
}

function approvePayment(User $validator, string $attemptId, int $amount, bool $withStepUp = true): TestResponse
{
    return test()->withToken(tokenFor($validator, withStepUp: $withStepUp))
        ->postJson("/api/v1/validations/payments/{$attemptId}/approve", ['received_amount' => $amount, 'reference' => 'REÇU-2026-0042'], ['Idempotency-Key' => (string) Str::ulid()]);
}

it('collects a cash membership payment as on the former site: instructions, declaration, validation', function () {
    app(SettingsService::class)->set('payment.manual_instructions', ['CASH' => 'Remettez {amount} FCFA au siège, référence {reference}.'], 'Instructions de recette');

    $response = payMembershipWith($this->member, 'CASH')
        ->assertCreated()
        ->assertJsonPath('data.mode', 'manual')
        ->assertJsonPath('data.method', 'CASH')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.checkout_url', null)
        ->assertJsonPath('data.amount', 25000);

    $attemptId = $response->json('data.id');
    expect($response->json('data.instructions'))->toContain('25 000')->toContain($response->json('data.reference'));

    $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/payments/{$attemptId}/declare", ['reference' => 'Remis au siège le 07/10'])
        ->assertOk()
        ->assertJsonPath('data.declared_reference', 'Remis au siège le 07/10');

    $this->withToken(tokenFor($this->validator))->getJson('/api/v1/validations/queue')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'membership')
        ->assertJsonPath('data.0.label', 'Adhésion')
        ->assertJsonPath('data.0.member.pernum', '1001043953')
        ->assertJsonPath('data.0.amount_xof', 25000)
        ->assertJsonPath('data.0.method', 'CASH')
        ->assertJsonPath('data.0.status', 'pending');

    approvePayment($this->validator, $attemptId, 25000)->assertOk()->assertJsonPath('data.status', 'approved');

    expect($this->member->refresh()->status)->toBe(UserStatus::Active)
        ->and(AuditEvent::query()->where('action', 'payments.manual_validated')->sole()->actor_id)->toBe($this->validator->id)
        ->and(AuditEvent::query()->where('action', 'memberships.activated')->exists())->toBeTrue();

    $this->withToken(tokenFor($this->validator))->getJson('/api/v1/validations/queue')->assertJsonCount(0, 'data');
    $this->withToken(tokenFor($this->validator))->getJson('/api/v1/validations/queue?status=history')
        ->assertJsonPath('data.0.status', 'validated');
});

it('refuses a received amount that differs from what is owed, and lets the validator reject', function () {
    $attemptId = payMembershipWith($this->member, 'CASH')->json('data.id');

    approvePayment($this->validator, $attemptId, 20000)->assertStatus(422)->assertJsonPath('code', 'PAYMENT_AMOUNT_MISMATCH');

    $this->withToken(tokenFor($this->validator, withStepUp: true))->postJson("/api/v1/validations/payments/{$attemptId}/reject", ['reason' => 'Aucun versement reçu à ce nom'])
        ->assertOk()
        ->assertJsonPath('data.status', 'declined');

    expect($this->member->refresh()->status)->toBe(UserStatus::PendingPayment);
    approvePayment($this->validator, $attemptId, 25000)->assertStatus(409)->assertJsonPath('code', 'PAYMENT_NOT_AWAITING_VALIDATION');

    payMembershipWith($this->member, 'CASH')->assertCreated();
});

it('reserves the validation to authorised staff, with a step-up', function () {
    $attemptId = payMembershipWith($this->member, 'CASH')->json('data.id');
    $yemValidator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();

    approvePayment($yemValidator, $attemptId, 25000)->assertForbidden();
    approvePayment($this->validator, $attemptId, 25000, withStepUp: false)->assertForbidden()->assertJsonPath('code', 'STEP_UP_REQUIRED');

    // The YEM validator sees the YEM receipts of the queue, not the payments to validate.
    $this->withToken(tokenFor($yemValidator))->getJson('/api/v1/validations/queue')->assertOk()->assertJsonCount(0, 'data');

    approvePayment(User::factory()->staff(PermissionCatalog::ADMIN)->create(), $attemptId, 25000)->assertForbidden();
    approvePayment($this->validator, $attemptId, 25000)->assertOk();
});

it('activates a pack paid in cash once validated', function () {
    $member = User::factory()->create();
    $plan = makePlan();

    $attemptId = $this->withToken(tokenFor($member))
        ->postJson('/api/v1/me/packs', ['plan_id' => $plan->id, 'method' => 'CASH'], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()
        ->assertJsonPath('data.payment.mode', 'manual')
        ->json('data.payment.id');

    $this->withToken(tokenFor($this->validator))->getJson('/api/v1/validations/queue')->assertJsonPath('data.0.label', 'Pack Vendeur V1');

    approvePayment($this->validator, $attemptId, 100000)->assertOk();

    $pack = UserPack::query()->sole();
    expect($pack->status)->toBe(UserPackStatus::Active)
        ->and($pack->account()->first()->available)->toBe('125.000000');
});

it('lets the member change method: the unused attempt is abandoned', function () {
    $cash = payMembershipWith($this->member, 'CASH')->json('data.id');

    $tmoney = payMembershipWith($this->member, 'TMONEY')
        ->assertCreated()
        ->assertJsonPath('data.mode', 'psp')
        ->json('data');

    expect($tmoney['checkout_url'])->toStartWith('http')
        ->and(PaymentAttempt::query()->findOrFail($cash)->status)->toBe(PaymentAttemptStatus::Canceled);

    payMembershipWith($this->member, 'TMONEY')->assertOk()->assertJsonPath('data.id', $tmoney['id']);
});

it('follows the configured collection mode of each method', function () {
    app(SettingsService::class)->set('payment.collection_modes', ['TMONEY' => 'manual', 'FLOOZ' => 'psp', 'CASH' => 'manual', 'BANK_TRANSFER' => 'manual'], 'Contrat FedaPay en attente : T-Money déclaré');

    payMembershipWith($this->member, 'TMONEY')->assertJsonPath('data.mode', 'manual')->assertJsonPath('data.method', 'TMONEY');
});

it('uses the method preferred at registration when none is given', function () {
    $this->member->forceFill(['preferred_payment_method' => 'CASH'])->save();

    payMembershipWith($this->member, null)->assertJsonPath('data.method', 'CASH');
});

it('refuses a disabled method', function () {
    app(SettingsService::class)->set('payment.methods', [
        ['code' => 'TMONEY', 'label' => 'T-Money', 'channel' => 'mobile_money', 'enabled' => true],
        ['code' => 'CASH', 'label' => 'Espèces', 'channel' => 'manual', 'enabled' => false],
    ], 'Espèces suspendues');

    payMembershipWith($this->member, 'CASH')->assertStatus(422)->assertJsonPath('code', 'PAYMENT_METHOD_NOT_AVAILABLE');
});

it('expires a manual payment never declared, but keeps a declared one waiting for the validator', function () {
    $silent = payMembershipWith($this->member, 'CASH')->json('data.id');

    $this->travel(8)->days();
    $fresh = payMembershipWith($this->member, 'CASH')->assertCreated()->json('data.id');
    expect(PaymentAttempt::query()->findOrFail($silent)->status)->toBe(PaymentAttemptStatus::Expired);

    $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/payments/{$fresh}/declare", ['reference' => 'Versement du 15/10'])->assertOk();
    $this->travel(8)->days();

    payMembershipWith($this->member, 'CASH')->assertOk()->assertJsonPath('data.id', $fresh);
});

it('keeps payments private to their owner', function () {
    $attemptId = payMembershipWith($this->member, 'CASH')->json('data.id');
    $stranger = User::factory()->status(UserStatus::PendingPayment)->create();

    $this->withToken(tokenFor($stranger))->postJson("/api/v1/me/payments/{$attemptId}/declare", ['reference' => 'x'])->assertNotFound();
});
