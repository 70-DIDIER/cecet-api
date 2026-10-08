<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    $this->token = tokenFor($this->superadmin, withStepUp: true);
});

/**
 * @param  list<string>  $permissions
 */
function createRole(string $token, string $name, array $permissions): TestResponse
{
    return test()->withToken($token)->postJson('/api/v1/admin/roles', [
        'name' => $name,
        'label' => Str::headline($name),
        'permissions' => $permissions,
    ], ['Idempotency-Key' => (string) Str::ulid()]);
}

function roleNamed(string $name): Role
{
    return Role::query()->where('name', $name)->sole();
}

it('exposes the permission catalog and the system roles', function () {
    $this->withToken($this->token)->getJson('/api/v1/admin/permissions')
        ->assertOk()
        ->assertJsonFragment(['name' => 'roles.manage', 'reserved_to_superadmin' => true])
        ->assertJsonFragment(['incompatible_pairs' => app(PermissionCatalog::class)->incompatiblePairs()]);

    $this->withToken($this->token)->getJson('/api/v1/admin/roles')
        ->assertOk()
        ->assertJsonFragment(['name' => PermissionCatalog::SUPERADMIN, 'permissions' => ['*'], 'is_system' => true])
        ->assertJsonFragment(['name' => PermissionCatalog::VALIDATOR, 'is_system' => true]);
});

it('creates a custom role with assignable permissions', function () {
    createRole($this->token, 'support_membres', ['members.view', 'audit.view'])
        ->assertCreated()
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.permissions', ['audit.view', 'members.view']);

    expect(AuditEvent::query()->where('action', 'roles.created')->exists())->toBeTrue();
});

it('refuses reserved, unknown or incompatible permissions', function (array $permissions, string $code) {
    createRole($this->token, 'role_test', $permissions)->assertStatus(422)->assertJsonPath('code', $code);
})->with([
    'reserved' => [['roles.manage'], 'PERMISSION_RESERVED'],
    'unknown' => [['payouts.delete_everything'], 'PERMISSION_UNKNOWN'],
    'incompatible' => [['yem_validations.confirm', 'payouts.settle_manual'], 'SEGREGATION_OF_DUTIES_VIOLATION'],
]);

it('reconfigures a system role and audits the change', function () {
    $validator = roleNamed(PermissionCatalog::VALIDATOR);

    $this->withToken($this->token)->putJson("/api/v1/admin/roles/{$validator->id}/permissions", [
        'permissions' => ['yem_validations.view', 'yem_validations.confirm'],
        'reason' => 'Les litiges passent à l\'Administrateur',
    ])->assertOk()->assertJsonPath('data.permissions', ['yem_validations.confirm', 'yem_validations.view']);

    $audit = AuditEvent::query()->where('action', 'roles.permissions_updated')->sole();
    expect($audit->before['permissions'])->toContain('yem_validations.dispute')
        ->and($audit->after['permissions'])->not->toContain('yem_validations.dispute');
});

it('refuses a change that would make a holder violate segregation of duties', function () {
    createRole($this->token, 'lecture_validation', ['yem_validations.view']);
    User::factory()->staff(PermissionCatalog::ACCOUNTING, 'lecture_validation')->create();

    $this->withToken($this->token)->putJson('/api/v1/admin/roles/'.roleNamed('lecture_validation')->id.'/permissions', [
        'permissions' => ['yem_validations.view', 'yem_validations.confirm'],
        'reason' => 'Extension du rôle',
    ])->assertStatus(422)->assertJsonPath('code', 'SEGREGATION_OF_DUTIES_VIOLATION');
});

it('protects the Superadmin role and system roles', function () {
    $this->withToken($this->token)->putJson('/api/v1/admin/roles/'.roleNamed(PermissionCatalog::SUPERADMIN)->id.'/permissions', [
        'permissions' => [],
        'reason' => 'Réduction des droits',
    ])->assertStatus(422)->assertJsonPath('code', 'ROLE_PROTECTED');

    $this->withToken($this->token)->deleteJson('/api/v1/admin/roles/'.roleNamed(PermissionCatalog::ADMIN)->id, ['reason' => 'Nettoyage'])
        ->assertStatus(422)->assertJsonPath('code', 'ROLE_PROTECTED');
});

it('deletes a custom role only when nobody holds it', function () {
    createRole($this->token, 'temporaire', ['reports.view']);
    $holder = User::factory()->staff('temporaire')->create();
    $role = roleNamed('temporaire');

    $this->withToken($this->token)->deleteJson("/api/v1/admin/roles/{$role->id}", ['reason' => 'Fin de mission'])
        ->assertStatus(409)->assertJsonPath('code', 'ROLE_IN_USE');

    $holder->syncRoles([]);

    $this->withToken($this->token)->deleteJson("/api/v1/admin/roles/{$role->id}", ['reason' => 'Fin de mission'])->assertNoContent();
});

it('reserves role management to the Superadmin', function () {
    $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();

    createRole(tokenFor($admin, withStepUp: true), 'role_admin', ['reports.view'])->assertForbidden();
});
