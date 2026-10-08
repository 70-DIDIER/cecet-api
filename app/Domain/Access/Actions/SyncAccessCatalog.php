<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates catalog permissions and missing system roles. Default permissions are only applied
 * when a system role is created, so later reconfiguration by the Superadmin is preserved.
 */
class SyncAccessCatalog
{
    public const GUARD = 'web';

    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @return array{permissions: int, created_roles: list<string>}
     */
    public function handle(): array
    {
        $createdRoles = [];

        DB::transaction(function () use (&$createdRoles): void {
            foreach (array_keys($this->catalog->permissions()) as $name) {
                Permission::findOrCreate($name, self::GUARD);
            }

            foreach ($this->catalog->systemRoles() as $name => $definition) {
                $role = Role::query()->where('name', $name)->where('guard_name', self::GUARD)->first();

                if ($role !== null) {
                    $role->forceFill(['label' => $definition['label'], 'is_system' => true])->save();

                    continue;
                }

                $role = Role::query()->create([
                    'name' => $name,
                    'guard_name' => self::GUARD,
                    'label' => $definition['label'],
                    'is_system' => true,
                ]);
                $role->syncPermissions($definition['permissions']);
                $createdRoles[] = $name;
            }
        });

        $this->registrar->forgetCachedPermissions();

        return ['permissions' => count($this->catalog->permissions()), 'created_roles' => $createdRoles];
    }
}
