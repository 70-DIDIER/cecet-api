<?php

use App\Domain\Access\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $administrator = Role::query()->where('name', PermissionCatalog::ADMIN)->where('guard_name', 'web')->first();
        if ($administrator === null) {
            // Fresh installs migrate before roles are seeded: SyncAccessCatalog grants these permissions later.
            return;
        }

        foreach (['identity_verifications.view', 'identity_verifications.review'] as $name) {
            $administrator->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permissions stay: the Superadmin may have reassigned them to other roles since.
    }
};
