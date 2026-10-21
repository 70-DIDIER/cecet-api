<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Identity\Actions\ConfirmStepUp;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();

    $this->admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();

    $this->alice = User::factory()->create(['first_name' => 'Yendoukoa', 'last_name' => 'NAKORE', 'city' => 'Lomé', 'phone' => '+22892104097']);
    $this->alice->pernumIdentities()->create(['pernum' => '1001043953', 'kind' => 'personal', 'status' => 'declared']);
    subscribeAndPay($this->alice, makePlan(PackFamily::Vendeur, 'V1'));

    $this->bob = User::factory()->legalPerson()->create(['first_name' => 'Awa', 'last_name' => 'KOFFI']);
    $this->bob->pernumIdentities()->create(['pernum' => '1001292934', 'kind' => 'business', 'status' => 'verified']);

    app('auth')->forgetGuards();
});

it('lists members with their Pernum, packs and YEM balance, and filters them', function () {
    $this->actingAs($this->admin, 'web')->get('/backoffice/membres')
        ->assertOk()
        ->assertSee('Yendoukoa NAKORE')
        ->assertSee('1001043953')
        ->assertSee('Vendeur')
        ->assertSee("0,00\u{00A0}YEM");

    $this->get('/backoffice/membres?search=1001292934')->assertOk()->assertSee('1001292934')->assertDontSee('Yendoukoa NAKORE');
    $this->get('/backoffice/membres?family=VENDEUR')->assertOk()->assertSee('Yendoukoa NAKORE')->assertDontSee('1001292934');
    $this->get('/backoffice/membres?person_type=legal')->assertOk()->assertSee('1001292934')->assertDontSee('1001043953');
    $this->get('/backoffice/membres?status=bogus')->assertSessionHasErrors('status');
});

it('shows the full file of a member', function () {
    $this->actingAs($this->admin, 'web')->get("/backoffice/membres/{$this->alice->id}")
        ->assertOk()
        ->assertSee('Yendoukoa NAKORE')
        ->assertSee('+22892104097')
        ->assertSee('V1')
        ->assertSee('Derniers paiements')
        ->assertSee('Suspendre le compte');
});

it('does not open staff accounts as members', function () {
    $this->actingAs($this->admin, 'web')->get("/backoffice/membres/{$this->admin->id}")->assertNotFound();
});

it('suspends and reactivates a member after a step-up, with a reason, audited', function () {
    $stepUp = [ConfirmStepUp::SESSION_KEY => now()->addMinutes(10)->getTimestamp()];

    $this->actingAs($this->admin, 'web')->from("/backoffice/membres/{$this->alice->id}")
        ->post("/backoffice/membres/{$this->alice->id}/suspendre", ['reason' => 'Fraude suspectée'])
        ->assertRedirect(route('backoffice.step-up', ['return' => "/backoffice/membres/{$this->alice->id}"]));
    expect($this->alice->refresh()->status)->toBe(UserStatus::Active);

    $this->withSession($stepUp)->post("/backoffice/membres/{$this->alice->id}/suspendre", ['reason' => 'ok'])->assertSessionHasErrors('reason');

    $this->post("/backoffice/membres/{$this->alice->id}/suspendre", ['reason' => 'Fraude suspectée'])
        ->assertRedirect(route('backoffice.members.show', $this->alice));
    expect($this->alice->refresh()->status)->toBe(UserStatus::Suspended)
        ->and(AuditEvent::query()->where('subject_id', $this->alice->id)->latest('position')->first()->reason)->toBe('Fraude suspectée');

    $this->get("/backoffice/membres/{$this->alice->id}")->assertSee('Réactiver le compte');
    $this->post("/backoffice/membres/{$this->alice->id}/reactiver", ['reason' => 'Vérifications faites'])->assertRedirect();
    expect($this->alice->refresh()->status)->toBe(UserStatus::Active);
});

it('reserves the member screens to staff who may see them', function () {
    $validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();

    $this->actingAs($validator, 'web')->get('/backoffice/membres')->assertForbidden();
    $this->get("/backoffice/membres/{$this->alice->id}")->assertForbidden();
    $this->get('/backoffice')->assertOk()->assertDontSee('>Membres<', false);

    $this->actingAs($this->admin, 'web')->get('/backoffice')->assertOk()->assertSee('Membres');
});
