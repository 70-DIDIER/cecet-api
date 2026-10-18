<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Exchange\Enums\ExchangeStatus;
use App\Domain\Exchange\Models\ExchangeRequest;
use App\Domain\Identity\Actions\ConfirmStepUp;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    configureCectPernum();

    $this->validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    $this->accounting = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
    $this->member = User::factory()->status(UserStatus::PendingPayment)->create(['first_name' => 'Yendoukoa', 'last_name' => 'NAKORE']);
    $this->member->pernumIdentities()->create(['pernum' => '1001043953', 'kind' => 'personal', 'status' => 'declared']);

    $this->attemptId = $this->withToken(tokenFor($this->member))
        ->postJson('/api/v1/me/membership/payments', ['method' => 'CASH'], ['Idempotency-Key' => (string) Str::ulid()])
        ->json('data.id');
    $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/payments/{$this->attemptId}/declare", ['reference' => 'Remis au siège']);
    app('auth')->forgetGuards();
});

function stepUp(): array
{
    return [ConfirmStepUp::SESSION_KEY => now()->addMinutes(10)->getTimestamp()];
}

it('lists what awaits a decision with the member, the amount and the declared reference', function () {
    $this->actingAs($this->accounting, 'web')->get('/backoffice/validations')
        ->assertOk()
        ->assertSee('Adhésion')
        ->assertSee('Yendoukoa NAKORE')
        ->assertSee('1001043953')
        ->assertSee("25\u{00A0}000\u{00A0}FCFA")
        ->assertSee('Remis au siège')
        ->assertSee('Confirmer mon identité');
});

it('asks for a step-up before a decision and does not decide', function () {
    $this->actingAs($this->accounting, 'web')
        ->from('/backoffice/validations')
        ->post("/backoffice/validations/paiements/{$this->attemptId}/valider", ['received_amount' => 25000, 'reference' => 'Reçu 1'])
        ->assertRedirect(route('backoffice.step-up', ['return' => '/backoffice/validations']));

    expect($this->member->refresh()->status)->toBe(UserStatus::PendingPayment);
});

it('validates a payment once the identity is confirmed, audits it and informs the member', function () {
    $this->actingAs($this->accounting, 'web')->withSession(stepUp())
        ->post("/backoffice/validations/paiements/{$this->attemptId}/valider", ['received_amount' => 25000, 'reference' => 'Reçu 1', 'comment' => 'Espèces comptées'])
        ->assertRedirect(route('backoffice.validations'))
        ->assertSessionHas('status');

    expect($this->member->refresh()->status)->toBe(UserStatus::Active)
        ->and(AuditEvent::query()->where('action', 'payments.manual_validated')->sole()->actor_id)->toBe($this->accounting->id);

    $this->get('/backoffice/validations?statut=historique')->assertOk()->assertSee('Validé');
});

it('refuses a wrong amount with a message and leaves the payment waiting', function () {
    $this->actingAs($this->accounting, 'web')->withSession(stepUp())
        ->from('/backoffice/validations')
        ->post("/backoffice/validations/paiements/{$this->attemptId}/valider", ['received_amount' => 20000, 'reference' => 'Reçu 1'])
        ->assertRedirect('/backoffice/validations')
        ->assertSessionHasErrors('decision');

    expect($this->member->refresh()->status)->toBe(UserStatus::PendingPayment);
});

it('rejects a payment with a mandatory reason', function () {
    $this->actingAs($this->accounting, 'web')->withSession(stepUp())
        ->post("/backoffice/validations/paiements/{$this->attemptId}/rejeter", ['reason' => 'ok'])
        ->assertSessionHasErrors('reason');

    $this->post("/backoffice/validations/paiements/{$this->attemptId}/rejeter", ['reason' => 'Aucun versement à ce nom'])
        ->assertRedirect(route('backoffice.validations'));

    expect(AuditEvent::query()->where('action', 'payments.manual_rejected')->exists())->toBeTrue();
});

it('keeps the decisions to the roles that hold the permission', function () {
    $accounting = User::factory()->staff(PermissionCatalog::ADMIN)->create();

    $this->actingAs($accounting, 'web')->withSession(stepUp())
        ->post("/backoffice/validations/paiements/{$this->attemptId}/valider", ['received_amount' => 25000, 'reference' => 'x'])
        ->assertForbidden();

    $this->actingAs($accounting, 'web')->get('/backoffice/validations')->assertOk()->assertDontSee('Adhésion');
});

it('examines a YEM receipt and confirms it with the quantity actually received', function () {
    [$seller, $pack] = readySeller();
    $id = $this->withToken(tokenFor($seller))
        ->postJson('/api/v1/me/exchange-requests', ['user_pack_id' => $pack->id, 'quantity' => '40'], ['Idempotency-Key' => (string) Str::ulid()])
        ->json('data.id');
    $this->withToken(tokenFor($seller))->postJson("/api/v1/me/exchange-requests/{$id}/declare-sent", ['transfer_reference' => 'YEMPay 12']);
    app('auth')->forgetGuards();

    $this->actingAs($this->validator, 'web')->get("/backoffice/validations/yem/{$id}")
        ->assertOk()
        ->assertSee("40,000000\u{00A0}YEM")
        ->assertSee('YEMPay 12')
        ->assertSee('Confirmer la réception');

    $this->withSession(stepUp())
        ->post("/backoffice/validations/yem/{$id}/confirmer", ['quantity_received' => '35', 'external_reference' => 'YEMPay 12'])
        ->assertRedirect(route('backoffice.validations'));

    $exchange = ExchangeRequest::query()->findOrFail($id);
    expect($exchange->status)->toBe(ExchangeStatus::YemReceived)
        ->and($exchange->amount_due_xof)->toBe(17850);

    $this->get("/backoffice/validations/yem/{$id}")->assertOk()->assertSee('Cette demande est close');
});

it('places a receipt in dispute and rejects another', function () {
    [$seller, $pack] = readySeller();
    $ids = [];

    foreach (['10', '20'] as $quantity) {
        $ids[] = $this->withToken(tokenFor($seller))
            ->postJson('/api/v1/me/exchange-requests', ['user_pack_id' => $pack->id, 'quantity' => $quantity], ['Idempotency-Key' => (string) Str::ulid()])
            ->json('data.id');
    }

    app('auth')->forgetGuards();

    $this->actingAs($this->validator, 'web')->withSession(stepUp())
        ->post("/backoffice/validations/yem/{$ids[0]}/litige", ['comment' => 'Pernum source différent'])
        ->assertRedirect(route('backoffice.validations.exchange', $ids[0]));
    $this->post("/backoffice/validations/yem/{$ids[1]}/rejeter", ['reason' => 'Transfert introuvable'])
        ->assertRedirect(route('backoffice.validations'));

    expect(ExchangeRequest::query()->findOrFail($ids[0])->status)->toBe(ExchangeStatus::Disputed)
        ->and(ExchangeRequest::query()->findOrFail($ids[1])->status)->toBe(ExchangeStatus::Rejected);
});
