<?php

namespace App\Domain\Access\Http\Controllers;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Access\RoleManager;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RoleController
{
    public function __construct(
        private readonly RoleManager $roles,
        private readonly PermissionCatalog $catalog,
    ) {}

    /**
     * Permission catalog with segregation-of-duties rules.
     */
    public function permissions(): JsonResponse
    {
        return response()->json([
            'data' => [
                'permissions' => collect($this->catalog->permissions())
                    ->map(fn (array $definition, string $name): array => [
                        'name' => $name,
                        'description' => $definition['description'],
                        'reserved_to_superadmin' => $definition['reserved'] ?? false,
                    ])
                    ->values(),
                'incompatible_pairs' => $this->catalog->incompatiblePairs(),
            ],
        ]);
    }

    /**
     * Roles with their permissions and holder count.
     */
    public function index(): JsonResponse
    {
        $roles = Role::query()->with('permissions')->orderBy('name')->get();
        $holders = DB::table(config()->string('permission.table_names.model_has_roles'))
            ->selectRaw('role_id, count(*) as holders')
            ->groupBy('role_id')
            ->pluck('holders', 'role_id');

        return response()->json(['data' => $roles->map(fn (Role $role): array => $this->present($role, (int) ($holders[$role->id] ?? 0)))]);
    }

    /**
     * Create a custom role.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,40}$/'],
            'label' => ['required', 'string', 'max:100'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ]);

        $role = $this->roles->create($this->actor($request), $validated['name'], $validated['label'], array_values($validated['permissions']));

        return response()->json(['data' => $this->present($role->load('permissions'), 0)], 201);
    }

    /**
     * Replace the permissions of a role (never the Superadmin role).
     */
    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $role = $this->roles->syncPermissions($this->actor($request), $role, array_values($validated['permissions']), $validated['reason']);

        return response()->json(['data' => $this->present($role->load('permissions'), $role->users()->count())]);
    }

    /**
     * Delete a custom role that is no longer assigned.
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $this->roles->delete($this->actor($request), $role, $validated['reason']);

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Role $role, int $usersCount): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->getAttribute('label'),
            'is_system' => (bool) $role->getAttribute('is_system'),
            'permissions' => $role->name === PermissionCatalog::SUPERADMIN
                ? ['*']
                : $role->permissions->pluck('name')->sort()->values()->all(),
            'users_count' => $usersCount,
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
