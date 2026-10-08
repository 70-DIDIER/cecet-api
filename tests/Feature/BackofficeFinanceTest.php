<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Exchange\Actions\SellerExchangeService;
use App\Domain\Exchange\Actions\ValidationDesk;
use App\Domain\Identity\Actions\ConfirmStepUp;
use App\Domain\Payout\Models\ManualSettlement;
use App\Domain\Payout\Models\PayoutBatch;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    configureCectPernum();

    $this->validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    $this->accounting = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
    $this->checker = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
});

/**
 * Sells $quantity YEM at 500 F, has them confirmed, and returns the payout batch created by the confirmation.
 */
function uiPayout(string $quantity, string $method = 'TMONEY'): PayoutBatch
{
    [$member, $pack] = readySeller(['quota_per_period' => '5000'], $method);
    $exchange = app(SellerExchangeService::class)->create($member, $pack->load('version'), $quantity);
    app(ValidationDesk::class)->confirm(test()->validator, $exchange, $quantity, 'YEM-TX', null);

    return PayoutBatch::query()->where('payable_id', $exchange->id)->sole();
}

function uiStepUp(): array
{
    return [ConfirmStepUp::SESSION_KEY => now()->addMinutes(10)->getTimestamp()];
}

it('lists the payouts and opens one with its transfers', function () {
    $batch = uiPayout('1500', 'FLOOZ');
    app('auth')->forgetGuards();

    $this->actingAs($this->accounting, 'web')->get('/backoffice/finance/versements')
        ->assertOk()
        ->assertSee($batch->reference)
        ->assertSee("750\u{00A0}000\u{00A0}FCFA")
        ->assertSee('À envoyer');

    $this->get('/backoffice/finance/versements?status=paid')->assertOk()->assertDontSee($batch->reference);

    $this->get("/backoffice/finance/versements/{$batch->id}")
        ->assertOk()
        ->assertSee("500\u{00A0}000\u{00A0}FCFA")
        ->assertSee("250\u{00A0}000\u{00A0}FCFA")
        ->assertSee('Envoyer les virements Mobile Money');
});

it('sends the transfers only after a step-up', function () {
    $batch = uiPayout('1000');
    app('auth')->forgetGuards();

    $this->actingAs($this->accounting, 'web')->from("/backoffice/finance/versements/{$batch->id}")
        ->post("/backoffice/finance/versements/{$batch->id}/envoyer")
        ->assertRedirect(route('backoffice.step-up', ['return' => "/backoffice/finance/versements/{$batch->id}"]));
    expect($batch->refresh()->status->value)->toBe('awaiting_dispatch');

    $this->withSession(uiStepUp())->post("/backoffice/finance/versements/{$batch->id}/envoyer")
        ->assertRedirect(route('backoffice.finance.payout', $batch))
        ->assertSessionHas('status');
    expect($batch->refresh()->status->value)->not->toBe('awaiting_dispatch');
});

it('records a small manual settlement that settles the payout at once', function () {
    $batch = uiPayout('40', 'CASH');
    app('auth')->forgetGuards();

    $this->actingAs($this->accounting, 'web')->withSession(uiStepUp())
        ->post("/backoffice/finance/versements/{$batch->id}/reglement", [
            'method' => 'CASH', 'amount' => 20000, 'reference' => 'Reçu 0042', 'paid_at' => now()->subHour()->format('Y-m-d\TH:i'),
        ])
        ->assertRedirect(route('backoffice.finance.payout', $batch));

    expect($batch->refresh()->status->value)->toBe('paid')
        ->and($batch->remaining_amount)->toBe(0);
});

it('requires a second person to approve a large manual settlement', function () {
    $batch = uiPayout('3200');
    app('auth')->forgetGuards();

    $this->actingAs($this->accounting, 'web')->withSession(uiStepUp())
        ->post("/backoffice/finance/versements/{$batch->id}/reglement", [
            'method' => 'BANK_TRANSFER', 'amount' => 1600000, 'reference' => 'VIR-2026-77', 'paid_at' => now()->subHour()->format('Y-m-d\TH:i'),
        ])
        ->assertRedirect(route('backoffice.finance.payout', $batch));

    $settlement = ManualSettlement::query()->sole();
    expect($settlement->status->value)->toBe('pending_approval')
        ->and($batch->refresh()->remaining_amount)->toBe(1600000);

    $this->get('/backoffice/finance/reglements')->assertOk()->assertSee('VIR-2026-77')->assertSee('Enregistré par vous');

    $this->from('/backoffice/finance/reglements')->post("/backoffice/finance/reglements/{$settlement->id}/approuver")->assertSessionHasErrors('decision');
    expect($settlement->refresh()->status->value)->toBe('pending_approval');

    $this->actingAs($this->checker, 'web')->withSession(uiStepUp())
        ->post("/backoffice/finance/reglements/{$settlement->id}/approuver")
        ->assertRedirect(route('backoffice.finance.settlements'));

    expect($settlement->refresh()->status->value)->toBe('applied')
        ->and($batch->refresh()->status->value)->toBe('paid');
});

it('lets the approver reject a settlement with a reason', function () {
    $batch = uiPayout('3200');
    app('auth')->forgetGuards();

    $this->actingAs($this->accounting, 'web')->withSession(uiStepUp())
        ->post("/backoffice/finance/versements/{$batch->id}/reglement", [
            'method' => 'BANK_TRANSFER', 'amount' => 1600000, 'reference' => 'VIR-FAUX', 'paid_at' => now()->subHour()->format('Y-m-d\TH:i'),
        ]);
    $settlement = ManualSettlement::query()->sole();

    $this->actingAs($this->checker, 'web')->withSession(uiStepUp())
        ->post("/backoffice/finance/reglements/{$settlement->id}/rejeter", ['reason' => 'ok'])->assertSessionHasErrors('reason');
    $this->post("/backoffice/finance/reglements/{$settlement->id}/rejeter", ['reason' => 'Référence introuvable à la banque'])
        ->assertRedirect(route('backoffice.finance.settlements'));

    expect($settlement->refresh()->status->value)->toBe('rejected')
        ->and($batch->refresh()->remaining_amount)->toBe(1600000);
});

it('keeps the finance screens to the roles that hold the permissions', function () {
    $batch = uiPayout('1000');
    app('auth')->forgetGuards();

    $this->actingAs($this->validator, 'web')->get('/backoffice/finance/versements')->assertForbidden();
    $this->get("/backoffice/finance/versements/{$batch->id}")->assertForbidden();
    $this->withSession(uiStepUp())->post("/backoffice/finance/versements/{$batch->id}/envoyer")->assertForbidden();

    $this->actingAs($this->accounting, 'web')->get('/backoffice')->assertOk()->assertSee('Versements');
});
