<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Identity\Actions\ConfirmStepUp;
use App\Domain\Identity\Enums\UserStatus;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Validations;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    configureCectPernum();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = User::factory()->staff(PermissionCatalog::ADMIN)->create(['email' => 'admin@cect.example']);
    $this->validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create(['email' => 'validateur@cect.example']);
});

it('sends visitors to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/login')->assertOk()->assertSee('Back-office CECT');
});

it('never lets a member into the panel, even with a session of their own', function () {
    $member = User::factory()->create();

    $this->actingAs($member, 'web')->get('/admin')->assertRedirect('/admin/login');
    $this->assertGuest('web');
});

it('signs a staff member in with the password and then the code received by e-mail', function () {
    $page = Livewire::test(Login::class)
        ->fillForm(['email' => 'admin@cect.example', 'password' => 'Password123'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect($page->get('challengeId'))->not->toBeNull();
    $this->assertGuest('web');

    $page->fillForm(['code' => '000000'])->call('authenticate')->assertHasFormErrors(['code']);
    $this->assertGuest('web');

    $page->fillForm(['code' => lastOtpCode('admin@cect.example')])->call('authenticate')->assertRedirect();
    $this->assertAuthenticatedAs($this->admin, 'web');
});

it('refuses member credentials before any code is sent', function () {
    User::factory()->create(['email' => 'membre@cect.example']);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'membre@cect.example', 'password' => 'Password123'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    Mail::assertNothingQueued();
    $this->assertGuest('web');
});

it('shows the dashboard to a staff member', function () {
    $this->actingAs($this->admin, 'web')->get('/admin')->assertOk()->assertSee('Tableau de bord');
});

it('keeps the validations page to those who validate', function () {
    $withoutRole = User::factory()->staff()->create();

    $this->actingAs($withoutRole, 'web')->get('/admin/validations')->assertForbidden();
    $this->actingAs($this->validator, 'web')->get('/admin/validations')->assertOk()->assertSee('Validations');
});

describe('validations', function () {
    beforeEach(function () {
        $this->accountant = User::factory()->staff(PermissionCatalog::ACCOUNTING)->create();
        $this->member = User::factory()->status(UserStatus::PendingPayment)->create(['first_name' => 'Yendoukoa', 'last_name' => 'NAKORE']);
        $this->member->pernumIdentities()->create(['pernum' => '1001043953', 'kind' => 'personal', 'status' => 'declared']);

        $this->attemptId = $this->withToken(tokenFor($this->member))
            ->postJson('/api/v1/me/membership/payments', ['method' => 'CASH'], ['Idempotency-Key' => (string) Str::ulid()])
            ->json('data.id');
        $this->withToken(tokenFor($this->member))->postJson("/api/v1/me/payments/{$this->attemptId}/declare", ['reference' => 'Remis au siège']);
        app('auth')->forgetGuards();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    });

    it('lists the payment awaiting validation', function () {
        $this->actingAs($this->accountant, 'web');

        Livewire::test(Validations::class)->assertSee('Yendoukoa NAKORE')->assertSee('Remis au siège');
    });

    it('does not validate without the confirmation code', function () {
        $this->actingAs($this->accountant, 'web');

        Livewire::test(Validations::class)
            ->callTableAction('approvePayment', "payment:{$this->attemptId}", ['received_amount' => 25000, 'reference' => 'Reçu 1', 'step_up_code' => '000000']);

        expect($this->member->refresh()->status)->toBe(UserStatus::PendingPayment);
    });

    it('validates the payment once the identity is confirmed and informs the member', function () {
        $this->actingAs($this->accountant, 'web')->withSession([ConfirmStepUp::SESSION_KEY => now()->addMinutes(10)->getTimestamp()]);

        Livewire::test(Validations::class)
            ->callTableAction('approvePayment', "payment:{$this->attemptId}", ['received_amount' => 25000, 'reference' => 'Reçu 1', 'comment' => 'Espèces comptées'])
            ->assertHasNoTableActionErrors();

        expect($this->member->refresh()->status)->toBe(UserStatus::Active);
    });

    it('refuses an amount that differs from what is due', function () {
        $this->actingAs($this->accountant, 'web')->withSession([ConfirmStepUp::SESSION_KEY => now()->addMinutes(10)->getTimestamp()]);

        Livewire::test(Validations::class)
            ->callTableAction('approvePayment', "payment:{$this->attemptId}", ['received_amount' => 10000, 'reference' => 'Reçu 1']);

        expect($this->member->refresh()->status)->toBe(UserStatus::PendingPayment);
    });
});
