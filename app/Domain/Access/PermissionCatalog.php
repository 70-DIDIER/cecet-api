<?php

namespace App\Domain\Access;

/**
 * Permissions known to the application, the system roles and the segregation-of-duties rules.
 *
 * Permissions are declared in code (the code checks them) and assigned to roles in the database,
 * where the Superadmin can reconfigure them. Reserved permissions belong to the Superadmin only.
 */
final class PermissionCatalog
{
    public const SUPERADMIN = 'superadmin';

    public const ADMIN = 'administrateur';

    public const VALIDATOR = 'validateur';

    public const ACCOUNTING = 'comptabilite';

    /**
     * @return array<string, array{description: string, reserved?: bool}>
     */
    public function permissions(): array
    {
        return [
            'members.view' => ['description' => 'Consulter les membres et leurs fiches.'],
            'members.manage' => ['description' => 'Suspendre ou réactiver un membre.'],
            'identity_verifications.view' => ['description' => 'Consulter les pièces d\'identité et selfies envoyés par les membres.'],
            'identity_verifications.review' => ['description' => 'Valider ou rejeter la vérification d\'identité d\'un membre.'],
            'staff.view' => ['description' => 'Consulter les comptes internes.'],
            'staff.manage' => ['description' => 'Créer, suspendre et attribuer des rôles aux comptes internes (jamais Superadmin).'],
            'roles.view' => ['description' => 'Consulter les rôles et permissions.'],
            'roles.manage' => ['description' => 'Modifier les permissions des rôles internes système.', 'reserved' => true],
            'settings.view' => ['description' => 'Consulter le paramétrage.'],
            'settings.update' => ['description' => 'Modifier les paramètres de niveau Administrateur.'],
            'settings.update_critical' => ['description' => 'Modifier les paramètres critiques (niveau Superadmin).', 'reserved' => true],
            'audit.view' => ['description' => 'Consulter le journal d\'audit métier.'],
            'security_events.view' => ['description' => 'Consulter le journal de sécurité.', 'reserved' => true],
            'providers.manage' => ['description' => 'Configurer les prestataires de paiement et leurs secrets.', 'reserved' => true],
            'packs.view' => ['description' => 'Consulter le catalogue de packs.'],
            'packs.manage' => ['description' => 'Créer, versionner, publier, suspendre et retirer des packs.'],
            'user_packs.view' => ['description' => 'Consulter les packs détenus par les membres et leur journal de quota.'],
            'quota.adjust' => ['description' => 'Ajuster manuellement le quota d\'un pack (correction auditée).', 'reserved' => true],
            'yem_validations.view' => ['description' => 'Consulter la file des réceptions YEM.'],
            'yem_validations.confirm' => ['description' => 'Confirmer ou rejeter l\'arrivée de YEM sur le Pernum CECT.'],
            'yem_validations.dispute' => ['description' => 'Placer une réception YEM en litige.'],
            'payments.validate_manual' => ['description' => 'Valider ou rejeter les paiements déclarés hors prestataire (espèces, dépôts Mobile Money, virements).'],
            'payments.view' => ['description' => 'Consulter les tentatives de paiement entrant et les webhooks reçus.'],
            'payouts.view' => ['description' => 'Consulter les payouts et le rapprochement.'],
            'payouts.retry' => ['description' => 'Relancer les items de payout en échec.'],
            'payouts.settle_manual' => ['description' => 'Enregistrer un règlement manuel (banque/espèces).'],
            'payouts.approve_manual' => ['description' => 'Approuver un règlement manuel au-delà du seuil (maker-checker).', 'reserved' => true],
            'reports.view' => ['description' => 'Consulter les rapports.'],
            'reports.export' => ['description' => 'Exporter les rapports et snapshots.'],
            'imports.manage' => ['description' => 'Prévisualiser et approuver un import consolidé.'],
            'api_docs.view' => ['description' => 'Consulter la documentation interactive de l\'API (/docs/api).'],
        ];
    }

    /**
     * System roles and the permissions they receive when first created.
     * The Superadmin role holds every permission implicitly.
     *
     * @return array<string, array{label: string, permissions: list<string>}>
     */
    public function systemRoles(): array
    {
        return [
            self::SUPERADMIN => ['label' => 'Superadmin', 'permissions' => []],
            self::ADMIN => ['label' => 'Administrateur', 'permissions' => [
                'members.view', 'members.manage', 'identity_verifications.view', 'identity_verifications.review', 'staff.view', 'staff.manage', 'roles.view', 'user_packs.view',
                'settings.view', 'settings.update', 'audit.view', 'packs.view', 'packs.manage',
                'yem_validations.view', 'payments.view', 'payouts.view', 'reports.view', 'reports.export', 'imports.manage', 'api_docs.view',
            ]],
            self::ACCOUNTING => ['label' => 'Comptabilité', 'permissions' => [
                'yem_validations.view', 'yem_validations.confirm', 'yem_validations.dispute',
                'payments.validate_manual', 'payments.view', 'user_packs.view',
                'payouts.view', 'payouts.retry', 'payouts.settle_manual', 'reports.view', 'reports.export',
            ]],
        ];
    }

    /**
     * Pairs of permissions that a single identity must never hold together.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function incompatiblePairs(): array
    {
        // Comptabilité now carries the former Validator duties (v5).
        // Conflict control is per-operation (e.g. maker/checker IDs on payouts),
        // not a blanket prohibition on holding accounting/validation permissions.
        return [];
    }

    public function exists(string $permission): bool
    {
        return array_key_exists($permission, $this->permissions());
    }

    public function isReserved(string $permission): bool
    {
        return $this->permissions()[$permission]['reserved'] ?? false;
    }

    public function isSystemRole(string $role): bool
    {
        return array_key_exists($role, $this->systemRoles());
    }

    /**
     * @param  iterable<string>  $permissions
     * @return list<array{0: string, 1: string}>
     */
    public function violatedPairs(iterable $permissions): array
    {
        $held = collect($permissions)->flip();

        return array_values(array_filter(
            $this->incompatiblePairs(),
            fn (array $pair): bool => $held->has($pair[0]) && $held->has($pair[1]),
        ));
    }
}
