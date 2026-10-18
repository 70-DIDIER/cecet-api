<?php

namespace App\Domain\Access;

use App\Domain\Access\Exceptions\AccessException;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecuritySeverity;
use App\Domain\Audit\SecurityEventLogger;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Otp\OtpService;
use App\Domain\Identity\SessionTerminator;
use App\Domain\Membership\Enums\ActivationMode;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Models\Membership;
use App\Domain\Shared\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle of internal accounts and suspension of any account (staff or member).
 *
 * Accounts are nominative, created without password and activated by the person through an email code.
 * Nobody modifies their own privileges, only a Superadmin touches the Superadmin role or accounts,
 * and the last active Superadmin cannot be removed.
 */
class AccountManager
{
    public function __construct(
        private readonly RoleManager $roles,
        private readonly OtpService $otp,
        private readonly AuditLogger $auditLogger,
        private readonly SecurityEventLogger $securityLogger,
        private readonly SessionTerminator $terminator,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone?: string|null}  $identity
     * @param  list<string>  $roleNames
     */
    public function create(?User $actor, array $identity, array $roleNames): User
    {
        $this->assertCanGrant($actor, $roleNames);
        // Certificate-bound SuperAdmin is a unique identity. The regular
        // staff-creation form must never mint a second SuperAdmin account.
        if (in_array(PermissionCatalog::SUPERADMIN, $roleNames, true)
            && User::query()->role(PermissionCatalog::SUPERADMIN)->exists()) {
            throw AccessException::superadminProtected();
        }
        $roles = $this->roles->resolveAssignableRoles($roleNames);

        try {
            return DB::transaction(function () use ($actor, $identity, $roles, $roleNames): User {
                $user = User::query()->create([
                    'kind' => UserKind::Staff,
                    'first_name' => $identity['first_name'],
                    'last_name' => $identity['last_name'],
                    'email' => $identity['email'],
                    'phone' => $identity['phone'] ?? null,
                    'status' => config('staff_guardian.enabled') ? UserStatus::Active : UserStatus::PendingActivation,
                    'email_verified_at' => config('staff_guardian.enabled') ? now() : null,
                ]);
                $user->syncRoles($roles);

                $this->auditLogger->record('staff.created', $user, after: [
                    'email' => $user->email,
                    'roles' => $roleNames,
                ], actor: $actor);
                $this->securityLogger->record('privilege.staff_created', SecuritySeverity::Warning, $actor?->id, metadata: [
                    'staff_id' => $user->id,
                    'roles' => $roleNames,
                ]);

                if (! config('staff_guardian.enabled')) {
                    $this->otp->issue($user, OtpPurpose::AccountActivation);
                }

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('ACCOUNT_ALREADY_EXISTS', 'Un compte existe déjà avec cet email.', 409);
        }
    }

    /**
     * @param  list<string>  $roleNames
     */
    public function syncRoles(User $actor, User $staff, array $roleNames, string $reason): User
    {
        $this->assertStaff($staff);
        $this->assertCanManage($actor, $staff);
        $this->assertCanGrant($actor, $roleNames);
        if (in_array(PermissionCatalog::SUPERADMIN, $roleNames, true)
            && ! $staff->isSuperadmin()
            && User::query()->role(PermissionCatalog::SUPERADMIN)->exists()) {
            throw AccessException::superadminProtected();
        }

        $roles = $this->roles->resolveAssignableRoles($roleNames, $staff->id);

        return DB::transaction(function () use ($actor, $staff, $roles, $roleNames, $reason): User {
            $before = $staff->getRoleNames()->sort()->values()->all();

            if (in_array(PermissionCatalog::SUPERADMIN, $before, true) && ! in_array(PermissionCatalog::SUPERADMIN, $roleNames, true)) {
                $this->assertNotLastSuperadmin($staff);
            }

            $staff->syncRoles($roles);
            $after = collect($roleNames)->sort()->values()->all();

            $this->auditLogger->record('staff.roles_updated', $staff, ['roles' => $before], ['roles' => $after], reason: $reason, actor: $actor);
            $this->securityLogger->record('privilege.staff_roles_changed', SecuritySeverity::Warning, $actor->id, metadata: [
                'staff_id' => $staff->id,
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
            ]);

            return $staff->refresh();
        });
    }

    public function suspend(User $actor, User $target, string $reason): User
    {
        $this->assertCanManage($actor, $target);

        if (in_array($target->status, [UserStatus::Suspended, UserStatus::Closed], true)) {
            throw AccessException::invalidStatusTransition($target->status->value, UserStatus::Suspended->value);
        }

        if ($target->isSuperadmin()) {
            $this->assertNotLastSuperadmin($target);
        }

        return DB::transaction(function () use ($actor, $target, $reason): User {
            $before = $target->status;

            $target->forceFill([
                'status' => UserStatus::Suspended,
                'suspended_at' => now(),
                'status_before_suspension' => $before->value,
            ])->save();
            $revokedTokens = $this->terminator->terminateAll($target);

            $this->auditLogger->record($this->action($target, 'suspended'), $target, ['status' => $before->value], ['status' => UserStatus::Suspended->value], reason: $reason, actor: $actor);
            $this->securityLogger->record('account.suspended', SecuritySeverity::Warning, $actor->id, metadata: [
                'target_id' => $target->id,
                'revoked_tokens' => $revokedTokens,
            ]);

            return $target;
        });
    }

    /**
     * Reactivation restores the status the account had when it was suspended.
     */
    public function reactivate(User $actor, User $target, string $reason): User
    {
        $this->assertCanManage($actor, $target);

        if ($target->status !== UserStatus::Suspended) {
            throw AccessException::invalidStatusTransition($target->status->value, 'reactivated');
        }

        $restored = UserStatus::tryFrom((string) $target->status_before_suspension)
            ?? ($target->isStaff() ? UserStatus::PendingActivation : UserStatus::PendingEmailVerification);

        $paidMembership = $restored === UserStatus::PendingPayment
            ? Membership::query()->where('user_id', $target->id)->whereNotNull('paid_at')->first()
            : null;

        if ($paidMembership !== null) {
            $restored = UserStatus::Active;
        }

        return DB::transaction(function () use ($actor, $target, $reason, $restored, $paidMembership): User {
            $target->forceFill(['status' => $restored, 'suspended_at' => null, 'status_before_suspension' => null])->save();

            $paidMembership?->forceFill(['status' => MembershipStatus::Active, 'activated_at' => now(), 'activation_mode' => ActivationMode::Payment])->save();

            $this->auditLogger->record($this->action($target, 'reactivated'), $target, ['status' => UserStatus::Suspended->value], ['status' => $restored->value], reason: $reason, actor: $actor);
            $this->securityLogger->record('account.reactivated', SecuritySeverity::Info, $actor->id, metadata: ['target_id' => $target->id]);

            return $target;
        });
    }

    /**
     * Close an existing membership without deleting financial, quota, legal
     * or audit records. Closure is terminal; it revokes member sessions.
     */
    public function closeMember(User $actor, User $member, string $reason): User
    {
        $this->assertCanManage($actor, $member);
        if (! $member->isMember() || $member->status === UserStatus::Closed) {
            throw AccessException::invalidStatusTransition($member->status->value, 'closed');
        }

        return DB::transaction(function () use ($actor, $member, $reason): User {
            $locked = User::query()->lockForUpdate()->findOrFail($member->id);
            $before = $locked->status;
            $locked->forceFill([
                'status' => UserStatus::Closed,
                'suspended_at' => now(),
                'status_before_suspension' => null,
            ])->save();
            $sessions = $this->terminator->terminateAll($locked);
            $this->auditLogger->record('members.closed', $locked,
                before: ['status' => $before->value],
                after: ['status' => 'closed'],
                reason: $reason, actor: $actor);
            $this->securityLogger->record('account.closed', SecuritySeverity::Warning, $actor->id,
                metadata: ['target_id' => $locked->id, 'revoked_sessions' => $sessions]);
            return $locked;
        });
    }

    private function action(User $target, string $verb): string
    {
        return ($target->isStaff() ? 'staff.' : 'members.').$verb;
    }

    private function assertStaff(User $user): void
    {
        if (! $user->isStaff()) {
            throw AccessException::wrongAccountKind();
        }
    }

    private function assertCanManage(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw AccessException::selfModification();
        }

        if ($target->isSuperadmin() && ! $actor->isSuperadmin()) {
            throw AccessException::superadminProtected();
        }
    }

    /**
     * @param  list<string>  $roleNames
     */
    private function assertCanGrant(?User $actor, array $roleNames): void
    {
        $grantsSuperadmin = in_array(PermissionCatalog::SUPERADMIN, $roleNames, true);

        if ($grantsSuperadmin && $actor !== null && ! $actor->isSuperadmin()) {
            throw AccessException::superadminProtected();
        }
    }

    private function assertNotLastSuperadmin(User $superadmin): void
    {
        $otherActiveSuperadmins = User::query()
            ->role(PermissionCatalog::SUPERADMIN)
            ->where('status', UserStatus::Active)
            ->whereKeyNot($superadmin->id)
            ->lockForUpdate()
            ->pluck('id');

        if ($otherActiveSuperadmins->isEmpty()) {
            throw AccessException::lastSuperadmin();
        }
    }
}
