<?php

namespace App\Domain\Backoffice\Support;

/**
 * French labels and badge colours of the statuses shown in the back-office. Unknown values are shown as they are.
 */
final class Labels
{
    /**
     * @var array<string, array<string, array{0: string, 1: string}>> group => value => [label, badge class]
     */
    private const GROUPS = [
        'account' => [
            'pending_email_verification' => ['E-mail à vérifier', 'is-warning'],
            'pending_payment' => ['Adhésion à payer', 'is-warning'],
            'pending_activation' => ['Activation en attente', 'is-warning'],
            'active' => ['Actif', 'is-success'],
            'suspended' => ['Suspendu', 'is-danger'],
            'closed' => ['Clôturé', 'is-muted'],
        ],
        'membership' => [
            'pending_payment' => ['À payer', 'is-warning'],
            'active' => ['Payée', 'is-success'],
        ],
        'pack' => [
            'pending_payment' => ['À payer', 'is-warning'],
            'active' => ['Actif', 'is-success'],
            'expired' => ['Expiré', 'is-muted'],
            'canceled' => ['Annulé', 'is-muted'],
        ],
        'payment' => [
            'created' => ['Créé', 'is-muted'],
            'pending' => ['En attente', 'is-warning'],
            'approved' => ['Validé', 'is-success'],
            'declined' => ['Refusé', 'is-danger'],
            'canceled' => ['Annulé', 'is-muted'],
            'expired' => ['Expiré', 'is-muted'],
            'failed' => ['Échoué', 'is-danger'],
            'mismatch' => ['Écart de montant', 'is-danger'],
        ],
        'exchange' => [
            'awaiting_transfer' => ['En attente du transfert', 'is-warning'],
            'declared_sent' => ['Envoi déclaré', 'is-warning'],
            'yem_received' => ['YEM reçus', 'is-success'],
            'completed' => ['Terminée', 'is-success'],
            'canceled' => ['Annulée', 'is-muted'],
            'expired' => ['Expirée', 'is-muted'],
            'rejected' => ['Rejetée', 'is-danger'],
            'disputed' => ['En litige', 'is-warning'],
        ],
        'merchant_payment' => [
            'reserved' => ['Réservé', 'is-warning'],
            'declared_sent' => ['Envoi déclaré', 'is-warning'],
            'confirmed' => ['Confirmé', 'is-success'],
            'canceled' => ['Annulé', 'is-muted'],
            'expired' => ['Expiré', 'is-muted'],
            'disputed' => ['En litige', 'is-warning'],
            'rejected' => ['Rejeté', 'is-danger'],
        ],
        'payout' => [
            'awaiting_dispatch' => ['À envoyer', 'is-warning'],
            'processing' => ['En cours', 'is-warning'],
            'partially_paid' => ['Partiellement versé', 'is-warning'],
            'paid' => ['Versé', 'is-success'],
            'failed' => ['Échec', 'is-danger'],
            'manual_required' => ['Règlement manuel requis', 'is-danger'],
        ],
        'payout_item' => [
            'pending' => ['À envoyer', 'is-warning'],
            'processing' => ['En cours', 'is-warning'],
            'succeeded' => ['Versé', 'is-success'],
            'failed' => ['Échec', 'is-danger'],
            'canceled' => ['Annulé', 'is-muted'],
        ],
        'settlement' => [
            'pending_approval' => ['En attente de validation', 'is-warning'],
            'applied' => ['Appliqué', 'is-success'],
            'rejected' => ['Rejeté', 'is-danger'],
        ],
        'plan' => [
            'draft' => ['Brouillon', 'is-muted'],
            'published' => ['Publié', 'is-success'],
            'suspended' => ['Suspendu', 'is-warning'],
            'retired' => ['Retiré', 'is-muted'],
            'archived' => ['Archivé', 'is-muted'],
        ],
    ];

    private const FAMILIES = ['VENDEUR' => 'Vendeur', 'CONSOMMATEUR' => 'Consommateur', 'PERSONNEL' => 'Personnel', 'MARCHAND' => 'Marchand'];

    public static function label(string $group, string $value): string
    {
        return self::GROUPS[$group][$value][0] ?? $value;
    }

    public static function badge(string $group, string $value): string
    {
        return self::GROUPS[$group][$value][1] ?? 'is-muted';
    }

    public static function family(string $value): string
    {
        return self::FAMILIES[$value] ?? $value;
    }

    /**
     * @return array<string, string>
     */
    public static function families(): array
    {
        return self::FAMILIES;
    }

    public static function personType(?string $value): string
    {
        return match ($value) {
            'natural' => 'Personne physique',
            'legal' => 'Personne morale',
            default => '—',
        };
    }
}
