<?php

namespace App\Domain\Access\Http\Controllers;

use App\Domain\Access\AccountManager;
use App\Domain\Access\AccountSearch;
use App\Domain\Access\Exceptions\AccessException;
use App\Domain\Identity\Enums\PersonType;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Http\Requests\RegisterMemberRequest;
use App\Domain\Identity\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Back-office administration of internal accounts and members.
 * The account kind is fixed by the route ("staff" or "members").
 */
class AccountController
{
    public function __construct(private readonly AccountManager $accounts) {}

    /**
     * Paginated list of accounts of the given kind.
     */
    public function index(Request $request, UserKind $kind, AccountSearch $search): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'person_type' => ['nullable', Rule::enum(PersonType::class)],
            'role' => ['nullable', 'string', 'max:60'],
            'search' => ['nullable', 'string', 'max:100'],
            'family' => ['nullable', 'string', 'in:VENDEUR,CONSOMMATEUR,PERSONNEL,MARCHAND'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = $search->builder($kind, $validated)->paginate($validated['per_page'] ?? 25);

        return UserResource::collection($users);
    }

    public function show(User $user, UserKind $kind): UserResource
    {
        $this->assertKind($user, $kind);

        return new UserResource($user->load(['organization', 'pernumIdentities', 'roles']));
    }

    /**
     * Create an internal account. The person receives an activation code by email.
     */
    public function storeStaff(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'regex:'.RegisterMemberRequest::PHONE_FORMAT],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct'],
        ]);

        $user = $this->accounts->create($this->actor($request), [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => mb_strtolower($validated['email']),
            'phone' => $validated['phone'] ?? null,
        ], array_values($validated['roles']));

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    /**
     * Replace the roles of an internal account.
     */
    public function updateRoles(Request $request, User $user): UserResource
    {
        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $user = $this->accounts->syncRoles($this->actor($request), $user, array_values($validated['roles']), $validated['reason']);

        return new UserResource($user->load('roles'));
    }

    /**
     * Suspend an account and revoke all its sessions.
     */
    public function suspend(Request $request, User $user, UserKind $kind): UserResource
    {
        $this->assertKind($user, $kind);

        return new UserResource($this->accounts->suspend($this->actor($request), $user, $this->reason($request)));
    }

    /**
     * Reactivate a suspended account in the status it had before suspension.
     */
    public function reactivate(Request $request, User $user, UserKind $kind): UserResource
    {
        $this->assertKind($user, $kind);

        return new UserResource($this->accounts->reactivate($this->actor($request), $user, $this->reason($request)));
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'];
    }

    private function assertKind(User $user, UserKind $kind): void
    {
        if ($user->kind !== $kind) {
            throw AccessException::wrongAccountKind();
        }

        Gate::authorize($kind === UserKind::Staff ? 'staff.view' : 'members.view');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
