<?php

use App\Domain\Access\AccountManager;
use App\Domain\Access\Exceptions\AccessException;
use App\Domain\Access\PermissionCatalog;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    $this->admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
});

/**
 * @param  list<string>  $roles
 */
function createStaff(User $actor, array $roles, string $email = 'agent@cect.tg'): TestResponse
{
    return test()->withToken(tokenFor($actor, withStepUp: true))->postJson('/api/v1/admin/staff', [
        'first_name' => 'Kofi',
        'last_name' => 'Agbeko',
        'email' => $email,
        'roles' => $roles,
    ], ['Idempotency-Key' => (string) Str::ulid()]);
}

it('creates an internal account that activates itself with an email code', function () {
    createStaff($this->admin, [PermissionCatalog::VALIDATOR])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending_activation')
        ->assertJsonPath('data.roles', [PermissionCatalog::VALIDATOR]);

    $this->postJson('/api/v1/auth/staff/activate', [
        'email' => 'agent@cect.tg',
        'code' => lastOtpCode('agent@cect.tg'),
        'password' => 'Activation2026',
        'password_confirmation' => 'Activation2026',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', ['email' => 'agent@cect.tg', 'password' => 'Activation2026', 'device_name' => 'tests'])
        ->assertStatus(202);
});

it('lets only a Superadmin grant the Superadmin role', function () {
    createStaff($this->admin, [PermissionCatalog::SUPERADMIN])
        ->assertForbidden()
        ->assertJsonPath('code', 'SUPERADMIN_PROTECTED');

    createStaff($this->superadmin, [PermissionCatalog::SUPERADMIN])->assertCreated();
});

it('refuses role combinations that break segregation of duties', function () {
    createStaff($this->superadmin, [PermissionCatalog::VALIDATOR, PermissionCatalog::ACCOUNTING])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SEGREGATION_OF_DUTIES_VIOLATION');
});

it('forbids changing your own roles', function () {
    $this->withToken(tokenFor($this->admin, withStepUp: true))
        ->putJson("/api/v1/admin/staff/{$this->admin->id}/roles", ['roles' => [PermissionCatalog::SUPERADMIN], 'reason' => 'Auto-promotion'])
        ->assertForbidden()
        ->assertJsonPath('code', 'SELF_MODIFICATION_FORBIDDEN');
});

it('protects Superadmin accounts from administrators', function () {
    $this->withToken(tokenFor($this->admin, withStepUp: true))
        ->postJson("/api/v1/admin/staff/{$this->superadmin->id}/suspend", ['reason' => 'Tentative de blocage'])
        ->assertForbidden()
        ->assertJsonPath('code', 'SUPERADMIN_PROTECTED');
});

it('never removes the last active Superadmin', function () {
    $inactiveSuperadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->status(UserStatus::Suspended)->create();
    $accounts = app(AccountManager::class);

    expect(fn () => $accounts->suspend($inactiveSuperadmin, $this->superadmin, 'Retrait du dernier Superadmin actif'))
        ->toThrow(AccessException::class)
        ->and(fn () => $accounts->syncRoles($inactiveSuperadmin, $this->superadmin, [PermissionCatalog::ADMIN], 'Rétrogradation'))
        ->toThrow(AccessException::class);

    expect($this->superadmin->refresh()->isSuperadmin())->toBeTrue();
});

it('suspends an account, revokes its sessions and restores its status on reactivation', function () {
    $validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();
    $validatorToken = tokenFor($validator);

    $this->withToken(tokenFor($this->admin, withStepUp: true))
        ->postJson("/api/v1/admin/staff/{$validator->id}/suspend", ['reason' => 'Enquête interne'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    app('auth')->forgetGuards();
    $this->withToken($validatorToken)->getJson('/api/v1/me')->assertUnauthorized();

    $this->withToken(tokenFor($this->admin, withStepUp: true))
        ->postJson("/api/v1/admin/staff/{$validator->id}/reactivate", ['reason' => 'Enquête close'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('administers members separately from internal accounts', function () {
    $member = User::factory()->status(UserStatus::PendingPayment)->create();
    $token = tokenFor($this->admin, withStepUp: true);

    $this->withToken($token)->getJson('/api/v1/admin/members')->assertOk()->assertJsonPath('data.0.id', $member->id);
    $this->withToken($token)->getJson("/api/v1/admin/staff/{$member->id}")->assertStatus(422)->assertJsonPath('code', 'WRONG_ACCOUNT_KIND');

    $this->withToken($token)->postJson("/api/v1/admin/members/{$member->id}/suspend", ['reason' => 'Fraude suspectée'])->assertOk();
    $this->withToken($token)->postJson("/api/v1/admin/members/{$member->id}/reactivate", ['reason' => 'Levée de doute'])
        ->assertJsonPath('data.status', 'pending_payment');
});

it('closes the back-office to members and checks permissions per route', function () {
    $member = User::factory()->create();
    $validator = User::factory()->staff(PermissionCatalog::VALIDATOR)->create();

    $this->withToken(tokenFor($member))->getJson('/api/v1/admin/staff')
        ->assertForbidden()
        ->assertJsonPath('code', 'STAFF_ONLY');

    $this->withToken(tokenFor($validator))->getJson('/api/v1/admin/staff')
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('bootstraps the first Superadmin from the command line', function () {
    User::query()->delete();

    $this->artisan('cect:create-superadmin', ['email' => 'Direction@CECT.tg', 'first_name' => 'Afi', 'last_name' => 'Dossou'])
        ->assertSuccessful();

    $superadmin = User::query()->where('email', 'direction@cect.tg')->sole();
    expect($superadmin->isSuperadmin())->toBeTrue()
        ->and($superadmin->status)->toBe(UserStatus::PendingActivation)
        ->and($superadmin->password)->toBeNull();
    lastOtpCode('direction@cect.tg');

    $this->artisan('cect:create-superadmin', ['email' => 'autre@cect.tg', 'first_name' => 'Yao', 'last_name' => 'Kpodo'])
        ->assertFailed();
});
