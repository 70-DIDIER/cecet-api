<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\SecurityEventLogger;
use App\Models\User;

beforeEach(function () {
    seedCatalogs();
});

it('lets an administrator browse the business audit journal with filters', function () {
    $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    app(AuditLogger::class)->record('packs.published');

    $this->withToken(tokenFor($admin))->getJson('/api/v1/admin/audit-events?action=packs.published')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'packs.published')
        ->assertJsonStructure(['data' => [['hash', 'prev_hash', 'position', 'correlation_id']], 'next_cursor']);
});

it('reserves the security journal to the Superadmin', function () {
    $admin = User::factory()->staff(PermissionCatalog::ADMIN)->create();
    $superadmin = User::factory()->staff(PermissionCatalog::SUPERADMIN)->create();
    app(SecurityEventLogger::class)->record('auth.login_failed', identifier: 'x@example.com');

    $this->withToken(tokenFor($admin))->getJson('/api/v1/admin/security-events')->assertForbidden();

    $this->withToken(tokenFor($superadmin))->getJson('/api/v1/admin/security-events?event_type=auth.login_failed')
        ->assertOk()
        ->assertJsonPath('data.0.identifier', 'x@example.com');
});
