<?php

use App\Domain\Access\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $accounting = Role::query()->where('name', PermissionCatalog::ACCOUNTING)->firstOrFail();
            $legacy = Role::query()->where('name', PermissionCatalog::VALIDATOR)->first();
            foreach (['yem_validations.view', 'yem_validations.confirm', 'yem_validations.dispute'] as $permission) {
                $accounting->givePermissionTo($permission);
            }

            if ($legacy !== null) {
                $assignments = DB::table('model_has_roles')->where('role_id', $legacy->id)->get();
                foreach ($assignments as $assignment) {
                    DB::table('model_has_roles')->insertOrIgnore([
                        'role_id' => $accounting->id,
                        'model_type' => $assignment->model_type,
                        'model_id' => $assignment->model_id,
                    ]);
                }
                DB::table('model_has_roles')->where('role_id', $legacy->id)->delete();
                $legacy->delete();
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally irreversible: reintroducing a removed role or stripping
        // Accounting permissions would silently undo the latest governance rules.
    }
};
