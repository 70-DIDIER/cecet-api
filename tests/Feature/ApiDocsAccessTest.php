<?php

use App\Domain\Access\PermissionCatalog;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    seedCatalogs();
    Mail::fake();
});

it('refuses the interactive documentation to visitors and members', function () {
    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();

    $this->actingAs(User::factory()->create())->get('/docs/api')->assertForbidden();
});

it('opens the documentation to the Superadmin and to staff holding the api_docs.view permission', function () {
    $this->actingAs(User::factory()->staff(PermissionCatalog::SUPERADMIN)->create())->get('/docs/api')->assertOk();
    $this->actingAs(User::factory()->staff(PermissionCatalog::ADMIN)->create())->get('/docs/api.json')->assertOk()->assertJsonPath('info.title', 'CECT / YEM API');
});

it('keeps it closed to staff without the permission and to suspended staff', function () {
    $this->actingAs(User::factory()->staff(PermissionCatalog::VALIDATOR)->create())->get('/docs/api')->assertForbidden();
    $this->actingAs(User::factory()->staff(PermissionCatalog::ADMIN)->status(UserStatus::Suspended)->create())->get('/docs/api')->assertForbidden();
});
