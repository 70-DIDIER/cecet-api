<?php

namespace App\Domain\Access;

use App\Domain\Access\Actions\SyncAccessCatalog;
use App\Domain\Access\Exceptions\AccessException;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Configures roles and their permissions while enforcing the non-negotiable invariants:
 * the Superadmin role is untouchable, reserved permissions stay with the Superadmin,
 * and no identity may end up holding incompatible permissions.
 */
class RoleManager
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly AuditLogger $auditLogger,
        private readonly SecurityEventLogger $securityLogger,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @param  list<string>  $permissions
     */
    public function create(User $actor, string $name, string $label, array $permissions): Role
    {
        throw AccessException::customRolesDisabled();

        $this->assertAssignable($permissions);

        if (Role::query()->where('name', $name)->where('guard_name', SyncAccessCatalog::GUARD)->exists()) {
            throw AccessException::roleNameTaken($name);
        }

        return DB::transaction(function () use ($actor, $name, $label, $permissions): Role {
            $role = Role::query()->create([
                'name' => $name,
                'guard_name' => SyncAccessCatalog::GUARD,
                'label' => $label,
                'is_system' => false,
            ]);
            $role->syncPermissions($permissions);

            $this->auditLogger->record('roles.created', $role, after: ['name' => $name, 'label' => $label, 'permissions' => $permissions], actor: $actor);
            $this->registrar->forgetCachedPermissions();

            return $role;
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    public function syncPermissions(User $actor, Role $role, array $permissions, string $reason): Role
    {
        if (! $this->catalog->isSystemRole($role->name)) {
            throw AccessException::customRolesDisabled();
        }

        if ($role->name === PermissionCatalog::SUPERADMIN) {
            throw AccessException::roleProtected($role->name);
        }

        $this->assertAssignable($permissions);

        return DB::transaction(function () use ($actor, $role, $permissions, $reason): Role {
            $before = $role->permissions->pluck('name')->sort()->values()->all();

            $this->assertHoldersStayCompliant($role, $permissions);

            $role->syncPermissions($permissions);
            $this->registrar->forgetCachedPermissions();

            $after = collect($permissions)->sort()->values()->all();

            $this->auditLogger->record('roles.permissions_updated', $role, ['permissions' => $before], ['permissions' => $after], reason: $reason, actor: $actor);
            $this->securityLogger->record('privilege.role_permissions_changed', SecuritySeverity::Warning, $actor->id, metadata: [
                'role' => $role->name,
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
            ]);

            return $role->refresh();
        });
    }

    public function delete(User $actor, Role $role, string $reason): void
    {
        if ($role->getAttribute('is_system') === true || $this->catalog->isSystemRole($role->name)) {
            throw AccessException::roleProtected($role->name);
        }

        if ($role->users()->exists()) {
            throw AccessException::roleInUse($role->name);
        }

        DB::transaction(function () use ($actor, $role, $reason): void {
            $this->auditLogger->record('roles.deleted', $role, ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()], reason: $reason, actor: $actor);
            $role->delete();
            $this->registrar->forgetCachedPermissions();
        });
    }

    /**
     * Validates that the given role names exist and that the combined permissions of the target are compliant.
     *
     * @param  list<string>  $roleNames
     * @return list<Role>
     */
    public function resolveAssignableRoles(array $roleNames, ?string $userId = null): array
    {
        if (collect($roleNames)->contains(fn (string $role): bool => ! $this->catalog->isSystemRole($role))) {
            throw AccessException::customRolesDisabled();
        }

        $roles = Role::query()->with('permissions')->whereIn('name', $roleNames)->where('guard_name', SyncAccessCatalog::GUARD)->get();
        $missing = array_values(array_diff($roleNames, $roles->pluck('name')->all()));

        if ($missing !== []) {
            throw AccessException::unknownRoles($missing);
        }

        $violations = $this->catalog->violatedPairs($roles->flatMap(fn (Role $role) => $role->permissions->pluck('name')));

        if ($violations !== []) {
            throw AccessException::segregationOfDuties($violations, $userId);
        }

        return array_values($roles->all());
    }

    /**
     * @param  list<string>  $permissions
     */
    private function assertAssignable(array $permissions): void
    {
        $unknown = array_values(array_filter($permissions, fn (string $permission): bool => ! $this->catalog->exists($permission)));

        if ($unknown !== []) {
            throw AccessException::unknownPermissions($unknown);
        }

        $reserved = array_values(array_filter($permissions, fn (string $permission): bool => $this->catalog->isReserved($permission)));

        if ($reserved !== []) {
            throw AccessException::reservedPermissions($reserved);
        }

        $violations = $this->catalog->violatedPairs($permissions);

        if ($violations !== []) {
            throw AccessException::segregationOfDuties($violations);
        }
    }

    /**
     * Ensures that every holder of the role remains compliant with its other roles once the change applies.
     *
     * @param  list<string>  $newPermissions
     */
    private function assertHoldersStayCompliant(Role $role, array $newPermissions): void
    {
        User::query()
            ->role($role->name)
            ->with('roles')
            ->each(function (User $holder) use ($role, $newPermissions): void {
                $otherRoleIds = $holder->roles->pluck('id')->reject(fn (mixed $id): bool => $id === $role->id);

                $combined = Role::query()
                    ->with('permissions')
                    ->whereIn('id', $otherRoleIds)
                    ->get()
                    ->flatMap(fn (Role $other) => $other->permissions->pluck('name'))
                    ->merge($newPermissions);

                $violations = $this->catalog->violatedPairs($combined);

                if ($violations !== []) {
                    throw AccessException::segregationOfDuties($violations, $holder->id);
                }
            });
    }
}
