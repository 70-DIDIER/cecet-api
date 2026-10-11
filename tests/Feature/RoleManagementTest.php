<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
    $this->superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    $this->token = tokenFor($this->superadmin, withStepUp: true);
});

function systemRole(string $name): Role
{
    return Role::query()->where('name', $name)->sole();
}

it('exposes only the four internal system roles and the permission catalog', function () {
    $this->withToken($this->token)->getJson('/api/v1/admin/permissions')
        ->assertOk()
        ->assertJsonFragment(['name' => 'roles.manage', 'reserved_to_superadmin' => true]);

    $roles = $this->withToken($this->token)->getJson('/api/v1/admin/roles')->assertOk()->json('data');

    expect(collect($roles)->pluck('name')->sort()->values()->all())->toBe([
        PermissionCatalog::ADMIN,
        PermissionCatalog::ACCOUNTING,
        PermissionCatalog::SUPERADMIN,
        PermissionCatalog::VALIDATOR,
    ]);
});

it('does not expose an endpoint for creating arbitrary roles', function () {
    $this->withToken($this->token)->postJson('/api/v1/admin/roles', [
        'name' => 'operations',
        'label' => 'Operations',
        'permissions' => ['members.view'],
    ])->assertStatus(405);
});

it('reconfigures a system role and audits the change', function () {
    $validator = systemRole(PermissionCatalog::VALIDATOR);

    $this->withToken($this->token)->putJson("/api/v1/admin/roles/{$validator->id}/permissions", [
        'permissions' => ['yem_validations.view', 'yem_validations.confirm'],
        'reason' => 'Les litiges passent temporairement au Superadmin',
    ])->assertOk()->assertJsonPath('data.permissions', ['yem_validations.confirm', 'yem_validations.view']);

    expect(AuditEvent::query()->where('action', 'roles.permissions_updated')->exists())->toBeTrue();
});

it('refuses to merge YEM confirmation with manual payment control', function () {
    $validator = systemRole(PermissionCatalog::VALIDATOR);

    $this->withToken($this->token)->putJson("/api/v1/admin/roles/{$validator->id}/permissions", [
        'permissions' => ['yem_validations.view', 'yem_validations.confirm', 'payments.validate_manual'],
        'reason' => 'Tentative de fusion des fonctions',
    ])->assertStatus(422)->assertJsonPath('code', 'SEGREGATION_OF_DUTIES_VIOLATION');
});

it('protects the Superadmin role', function () {
    $this->withToken($this->token)->putJson('/api/v1/admin/roles/'.systemRole(PermissionCatalog::SUPERADMIN)->id.'/permissions', [
        'permissions' => [],
        'reason' => 'Réduction des droits',
    ])->assertStatus(422)->assertJsonPath('code', 'ROLE_PROTECTED');
});

it('refuses assigning a legacy custom role to staff', function () {
    $legacy = Role::query()->create(['name' => 'operations', 'guard_name' => 'web', 'label' => 'Legacy operations', 'is_system' => false]);

    $this->withToken($this->token)->postJson('/api/v1/admin/staff', [
        'first_name' => 'Kofi',
        'last_name' => 'Legacy',
        'email' => 'legacy-role@cect.test',
        'roles' => [$legacy->name],
    ])->assertStatus(422)->assertJsonPath('code', 'CUSTOM_ROLES_DISABLED');
});

it('reserves role permission management to the Superadmin', function () {
    $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $validator = systemRole(PermissionCatalog::VALIDATOR);

    $this->withToken(tokenFor($admin, withStepUp: true))->putJson("/api/v1/admin/roles/{$validator->id}/permissions", [
        'permissions' => ['yem_validations.view'],
        'reason' => 'Tentative administrateur',
    ])->assertForbidden();
});
