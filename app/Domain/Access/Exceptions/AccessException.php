<?php

namespace App\Domain\Access\Exceptions;

use App\Domain\Shared\Exceptions\ApiException;

final class AccessException extends ApiException
{
    public static function roleProtected(string $role): self
    {
        return new self('ROLE_PROTECTED', "Le rôle {$role} est protégé et ne peut pas être modifié ainsi.", 422, ['role' => $role]);
    }

    public static function customRolesDisabled(): self
    {
        return new self('CUSTOM_ROLES_DISABLED', 'Les rôles internes CECT sont fixes ; configurez leurs permissions au lieu de créer un nouveau rôle.', 422);
    }

    public static function roleInUse(string $role): self
    {
        return new self('ROLE_IN_USE', "Le rôle {$role} est encore attribué à des comptes.", 409, ['role' => $role]);
    }

    public static function roleNameTaken(string $role): self
    {
        return new self('ROLE_NAME_TAKEN', "Un rôle nommé {$role} existe déjà.", 409, ['role' => $role]);
    }

    /**
     * @param  list<string>  $roles
     */
    public static function unknownRoles(array $roles): self
    {
        return new self('ROLE_UNKNOWN', 'Rôle(s) inconnu(s).', 422, ['roles' => $roles]);
    }

    /**
     * @param  list<string>  $permissions
     */
    public static function unknownPermissions(array $permissions): self
    {
        return new self('PERMISSION_UNKNOWN', 'Permission(s) inconnue(s).', 422, ['permissions' => $permissions]);
    }

    /**
     * @param  list<string>  $permissions
     */
    public static function reservedPermissions(array $permissions): self
    {
        return new self('PERMISSION_RESERVED', 'Ces permissions sont réservées au Superadmin.', 422, ['permissions' => $permissions]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    public static function segregationOfDuties(array $pairs, ?string $userId = null): self
    {
        return new self('SEGREGATION_OF_DUTIES_VIOLATION', 'Cette combinaison de permissions viole la séparation des tâches.', 422, array_filter([
            'incompatible_pairs' => $pairs,
            'user_id' => $userId,
        ]));
    }

    public static function selfModification(): self
    {
        return new self('SELF_MODIFICATION_FORBIDDEN', 'Vous ne pouvez pas modifier vos propres droits ou statut.', 403);
    }

    public static function superadminProtected(): self
    {
        return new self('SUPERADMIN_PROTECTED', 'Seul un Superadmin peut gérer un compte ou le rôle Superadmin.', 403);
    }

    public static function lastSuperadmin(): self
    {
        return new self('LAST_SUPERADMIN', 'Le dernier Superadmin actif ne peut pas être retiré ou suspendu.', 409);
    }

    public static function wrongAccountKind(): self
    {
        return new self('WRONG_ACCOUNT_KIND', 'Cette opération ne s\'applique pas à ce type de compte.', 422);
    }

    public static function invalidStatusTransition(string $from, string $to): self
    {
        return new self('INVALID_STATUS_TRANSITION', "Transition de statut impossible : {$from} vers {$to}.", 409, ['from' => $from, 'to' => $to]);
    }
}
