<?php

namespace App\Domain\Settings;

use App\Domain\Notification\Notifier;
use App\Domain\Settings\Enums\SettingEditLevel;
use App\Domain\Settings\Enums\SettingType;

/**
 * Catalog of every configurable setting known to the application.
 *
 * Defaults are the working values of the specification (v4.0); several are still subject to
 * ratification by the CA and are expected to be changed by the Superadmin/Admin without deployment.
 */
final class SettingsCatalog
{
    /**
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        $definitions = [
            ...$this->membership(),
            ...$this->organization(),
            ...$this->auth(),
            ...$this->rates(),
            ...$this->packs(),
            ...$this->exchange(),
            ...$this->merchantPayments(),
            ...$this->payouts(),
            ...$this->payments(),
            ...$this->yem(),
            ...$this->otp(),
            ...$this->platform(),
            ...$this->notifications(),
            ...$this->reporting(),
            ...$this->ops(),
        ];

        return collect($definitions)->keyBy(fn (SettingDefinition $definition) => $definition->key)->all();
    }

    public function find(string $key): ?SettingDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return list<SettingDefinition>
     */
    private function membership(): array
    {
        return [
            new SettingDefinition('membership.fee.natural_person', 'membership', SettingType::Integer, 25000,
                'Frais d\'adhésion initiale d\'une personne physique (XOF).', rules: ['value' => ['required', 'min:0']]),
            new SettingDefinition('membership.fee.legal_person', 'membership', SettingType::Integer, 200000,
                'Frais d\'adhésion initiale d\'une personne morale (XOF).', rules: ['value' => ['required', 'min:0']]),
            new SettingDefinition('membership.commitment_version', 'membership', SettingType::String, 'statuts-ri-2026',
                'Version du texte d\'engagement (statuts et règlement intérieur) acceptée à l\'inscription ; à changer quand le texte change.',
                rules: ['value' => ['required', 'max:40']]),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function organization(): array
    {
        return [
            new SettingDefinition('organization.legal_forms', 'organization', SettingType::Json,
                ['SARL', 'SA', 'SAS', 'SASU', 'EURL', 'GIE', 'Association', 'Coopérative', 'Auto-entrepreneur', 'Autre'],
                'Statuts juridiques acceptés pour l\'inscription d\'une personne morale (PO-10).',
                rules: ['value' => ['required', 'min:1'], 'value.*' => ['string', 'max:40', 'distinct']]),
            new SettingDefinition('organization.rccm_required', 'organization', SettingType::Boolean, false,
                'Numéro RCCM obligatoire à l\'inscription d\'une personne morale (PO-10).'),
            new SettingDefinition('organization.nif_required', 'organization', SettingType::Boolean, false,
                'Numéro NIF obligatoire à l\'inscription d\'une personne morale (PO-10).'),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function auth(): array
    {
        return [
            new SettingDefinition('auth.password_min_length', 'auth', SettingType::Integer, 10,
                'Longueur minimale des mots de passe.', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'min:8', 'max:128']]),
            new SettingDefinition('auth.login_max_attempts', 'auth', SettingType::Integer, 5,
                'Nombre d\'échecs de connexion tolérés avant blocage temporaire (par email et adresse IP).',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:1', 'max:20']]),
            new SettingDefinition('auth.login_lockout_seconds', 'auth', SettingType::Integer, 900,
                'Durée du blocage temporaire après trop d\'échecs de connexion (secondes).',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:60']]),
            new SettingDefinition('auth.member_token_ttl_minutes', 'auth', SettingType::Integer, 10080,
                'Durée de validité d\'une session Utilisateur (minutes).', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'min:15']]),
            new SettingDefinition('auth.staff_token_ttl_minutes', 'auth', SettingType::Integer, 480,
                'Durée de validité d\'une session des rôles internes (minutes).', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'min:15', 'max:1440']]),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function rates(): array
    {
        return [
            new SettingDefinition('rates.purchase_xof_per_yem', 'rates', SettingType::Integer, 500,
                'Cours d\'achat d\'un YEM par la CECT (XOF) — à ratifier par le CA (PO-01).', rules: ['value' => ['required', 'min:1']]),
            new SettingDefinition('rates.sale_xof_per_yem', 'rates', SettingType::Integer, 650,
                'Cours de vente d\'un YEM par la CECT (XOF) — à ratifier par le CA (PO-01).', rules: ['value' => ['required', 'min:1']]),
            new SettingDefinition('rates.transfer_xof_per_yem', 'rates', SettingType::Integer, 610,
                'Cours de cession d\'un YEM (XOF) — à ratifier par le CA (PO-01).', rules: ['value' => ['required', 'min:1']]),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function packs(): array
    {
        return [
            new SettingDefinition('packs.max_structured_active', 'packs', SettingType::Integer, 2,
                'Nombre maximal de packs structurés actifs par compte.', rules: ['value' => ['required', 'min:0']]),
            new SettingDefinition('packs.pending_payment_ttl_hours', 'packs', SettingType::Integer, 24,
                'Au-delà de ce délai, un pack souscrit mais non payé (sans paiement en cours) est annulé automatiquement (heures).',
                rules: ['value' => ['required', 'min:1', 'max:720']]),
            new SettingDefinition('packs.families_excluded_from_limit', 'packs', SettingType::Json, ['PERSONNEL'],
                'Familles de packs non comptées dans la limite de packs structurés.',
                rules: ['value' => ['present'], 'value.*' => ['string', 'in:VENDEUR,CONSOMMATEUR,PERSONNEL,MARCHAND', 'distinct']]),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function exchange(): array
    {
        return [
            new SettingDefinition('exchange.reservation_ttl_minutes', 'exchange', SettingType::Integer, 1440,
                'Durée de réservation d\'une quantité de quota/solde avant expiration automatique (minutes).',
                rules: ['value' => ['required', 'min:5']]),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function ops(): array
    {
        return [
            new SettingDefinition('ops.alert_emails', 'ops', SettingType::Json, [],
                'Adresses qui reçoivent les alertes d\'exploitation (P1 critiques et P2 majeures).',
                SettingEditLevel::Superadmin, rules: ['value' => ['present', 'array', 'max:10'], 'value.*' => ['email', 'distinct']]),
            new SettingDefinition('ops.alert_repeat_minutes', 'ops', SettingType::Integer, 60,
                'Délai avant de renvoyer une même alerte tant que le problème persiste (minutes).',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:5', 'max:1440']]),
            new SettingDefinition('ops.payout_failure_threshold', 'ops', SettingType::Integer, 5,
                'Nombre de virements de contre-valeur en échec sur une heure à partir duquel une alerte est levée.',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:1']]),
            new SettingDefinition('ops.webhook_rejection_threshold', 'ops', SettingType::Integer, 20,
                'Nombre de webhooks rejetés (signature invalide) sur une heure à partir duquel une alerte est levée.',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:1']]),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function reporting(): array
    {
        return [
            new SettingDefinition('reporting.snapshots_enabled', 'reporting', SettingType::Boolean, true,
                'Génération automatique du snapshot consolidé après les opérations métier.'),
            new SettingDefinition('reporting.snapshot_min_interval_minutes', 'reporting', SettingType::Integer, 15,
                'Délai minimal entre deux snapshots automatiques (minutes) : les opérations rapprochées sont regroupées.',
                rules: ['value' => ['required', 'min:1', 'max:1440']]),
            new SettingDefinition('reporting.snapshot_retention_count', 'reporting', SettingType::Integer, 100,
                'Nombre d\'archives de snapshot conservées ; au-delà, le fichier est supprimé mais son hash reste dans l\'historique.',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:1', 'max:10000']]),
            new SettingDefinition('reporting.snapshot_trigger_prefixes', 'reporting', SettingType::Json,
                ['memberships.', 'packs.', 'quota.', 'exchanges.', 'payments.', 'payouts.', 'merchant_payments.', 'merchant_redemptions.', 'merchant_balance.', 'imports.'],
                'Actions du journal d\'audit (préfixes) qui déclenchent un nouveau snapshot.',
                rules: ['value' => ['present', 'array'], 'value.*' => ['string', 'max:60', 'distinct', 'not_in:snapshots.,reports.']]),
            new SettingDefinition('imports.member_fields', 'reporting', SettingType::Json, ['phone', 'city'],
                'Colonnes du fichier membres qu\'un réimport peut modifier. Les identités, Pernum, emails, adhésions et données financières ne sont jamais importables.',
                SettingEditLevel::Superadmin, rules: ['value' => ['present', 'array'], 'value.*' => ['string', 'in:phone,city', 'distinct']]),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function notifications(): array
    {
        return [
            new SettingDefinition('notifications.disabled_events', 'notifications', SettingType::Json, [],
                'Notifications email désactivées (membership.activated, payment.rejected, pack.activated, topup.credited, exchange.yem_received, exchange.rejected, merchant_payment.declared, merchant_payment.confirmed, merchant_payment.rejected).',
                rules: ['value' => ['present', 'array'], 'value.*' => ['string', 'in:'.implode(',', Notifier::EVENTS), 'distinct']]),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function merchantPayments(): array
    {
        return [
            new SettingDefinition('merchant_payment.reservation_ttl_minutes', 'merchant_payment', SettingType::Integer, 60,
                'Durée de réservation d\'un paiement marchand non déclaré avant libération automatique (minutes).',
                rules: ['value' => ['required', 'min:5']]),
            new SettingDefinition('merchant_payment.confirmation_mode', 'merchant_payment', SettingType::String, 'merchant',
                'Qui confirme qu\'un paiement marchand a été reçu : le marchand lui-même (« merchant ») ou un Validateur de la CECT (« validator »). En mode marchand, un paiement contesté est arbitré par un Validateur.',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'in:merchant,validator']]),
            new SettingDefinition('merchant_payment.payer_families', 'merchant_payment', SettingType::Json, ['CONSOMMATEUR', 'PERSONNEL'],
                'Familles de packs dont le quota/solde peut servir à payer un marchand.',
                rules: ['value' => ['present', 'array'], 'value.*' => ['string', 'in:VENDEUR,CONSOMMATEUR,PERSONNEL,MARCHAND', 'distinct']]),
            new SettingDefinition('merchant_redemption.limit_by_pack_quota', 'merchant_payment', SettingType::Boolean, true,
                'Un rééchange marchand consomme aussi le quota périodique du Pack Marchand (plafond de rééchange par période), en plus du solde des encaissements confirmés.',
                SettingEditLevel::Superadmin),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function payouts(): array
    {
        $providerFeesDefault = app()->environment('testing')
            ? ['fake' => ['rate_bps' => 0, 'fixed_xof' => 0]]
            : [];

        return [
            new SettingDefinition('payout.auto_enabled', 'payout', SettingType::Boolean, false,
                'Envoi automatique des payouts au PSP après confirmation du Validateur.', SettingEditLevel::Superadmin),
            new SettingDefinition('payout.mobile_money.unit_max_xof', 'payout', SettingType::Integer, 500000,
                'Montant maximal d\'un envoi Mobile Money unitaire (XOF).', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'min:1']]),
            new SettingDefinition('payout.mobile_money.max_items', 'payout', SettingType::Integer, 3,
                'Nombre maximal d\'envois Mobile Money pour une même obligation ; au-delà : virement ou espèces.',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:1']]),
            new SettingDefinition('payout.fees_borne_by', 'payout', SettingType::String, 'cooperative',
                'Qui supporte les frais de décaissement (PO-09) : « cooperative » (le bénéficiaire reçoit le montant dû) ou « beneficiary » (le montant envoyé est réduit des frais).',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'in:cooperative,beneficiary']]),
            new SettingDefinition('payout.provider_fees', 'payout', SettingType::Json,
                $providerFeesDefault,
                'Barème des frais de décaissement par prestataire : rate_bps (centièmes de %), fixed_xof (part fixe par envoi).',
                SettingEditLevel::Superadmin, rules: [
                    'value' => ['required'],
                    'value.*.rate_bps' => ['required', 'integer', 'min:0', 'max:5000'],
                    'value.*.fixed_xof' => ['required', 'integer', 'min:0'],
                ]),
            new SettingDefinition('validation.require_external_reference', 'payout', SettingType::Boolean, true,
                'Le Validateur doit saisir la référence de la transaction YEM pour confirmer une réception (PO-06).'),
            new SettingDefinition('finance.maker_checker_threshold_xof', 'payout', SettingType::Integer, 1500000,
                'Montant à partir duquel un règlement manuel exige une seconde validation (XOF) — à confirmer (PO-08).',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:0']]),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function payments(): array
    {
        $testEnvironment = app()->environment('testing');
        $providerDefault = $testEnvironment ? 'fake' : 'fedapay';
        $customerBearsFeesDefault = $testEnvironment;
        $feeRulesDefault = $testEnvironment
            ? ['fake' => ['rate_bps' => 0, 'fixed_xof' => 0, 'rounding_xof' => 1], 'manual' => ['rate_bps' => 0, 'fixed_xof' => 0, 'rounding_xof' => 1]]
            : ['manual' => ['rate_bps' => 0, 'fixed_xof' => 0, 'rounding_xof' => 1]];

        return [
            new SettingDefinition('payment.inbound_provider', 'payment', SettingType::String, $providerDefault,
                'Prestataire des paiements entrants (adhésions, packs).', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'in:fake,fedapay']], productionForbiddenValues: ['fake']),
            new SettingDefinition('payment.payout_provider', 'payment', SettingType::String, $providerDefault,
                'Prestataire des décaissements (payouts).', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'in:fake,fedapay']], productionForbiddenValues: ['fake']),
            new SettingDefinition('payment.customer_bears_fees', 'payment', SettingType::Boolean, $customerBearsFeesDefault,
                'Les frais de transaction du prestataire sont ajoutés au montant payé par le client, pour que la CECT reçoive le montant dû net.',
                SettingEditLevel::Superadmin),
            new SettingDefinition('payment.customer_fees', 'payment', SettingType::Json,
                $feeRulesDefault,
                'Barème des frais par prestataire, d\'après le contrat : rate_bps (pourcentage en centièmes de %, 300 = 3 %), fixed_xof (part fixe, XOF), rounding_xof (arrondi du total au multiple supérieur). Un prestataire sans entrée est refusé.',
                SettingEditLevel::Superadmin, rules: [
                    'value' => ['required'],
                    'value.*.rate_bps' => ['required', 'integer', 'min:0', 'max:5000'],
                    'value.*.fixed_xof' => ['required', 'integer', 'min:0'],
                    'value.*.rounding_xof' => ['sometimes', 'integer', 'min:1', 'max:1000'],
                ]),
            new SettingDefinition('payment.collection_modes', 'payment', SettingType::Json,
                ['TMONEY' => 'psp', 'FLOOZ' => 'psp', 'CASH' => 'manual', 'BANK_TRANSFER' => 'manual'],
                'Mode d\'encaissement de chaque moyen de paiement : « psp » (page de paiement du prestataire, confirmation automatique) ou « manual » (paiement déclaré puis validé à la main, comme sur l\'ancien site).',
                rules: ['value' => ['required'], 'value.*' => ['in:psp,manual']]),
            new SettingDefinition('payment.manual_instructions', 'payment', SettingType::Json, [
                'CASH' => 'Remettez {amount} FCFA en espèces à la CECT en indiquant la référence {reference}.',
                'TMONEY' => 'Envoyez {amount} FCFA par T-Money au numéro de la CECT, puis déclarez l\'identifiant de la transaction (référence {reference}).',
                'FLOOZ' => 'Envoyez {amount} FCFA par Flooz au numéro de la CECT, puis déclarez l\'identifiant de la transaction (référence {reference}).',
                'BANK_TRANSFER' => 'Virez {amount} FCFA sur le compte bancaire de la CECT avec le libellé {reference}, puis déclarez la référence du virement.',
            ], 'Instructions affichées au membre pour un paiement validé à la main ({amount} et {reference} sont remplacés).',
                rules: ['value' => ['required'], 'value.*' => ['string', 'max:500']]),
            new SettingDefinition('payment.manual_declaration_ttl_days', 'payment', SettingType::Integer, 7,
                'Au-delà de ce délai, un paiement manuel ni déclaré ni validé expire et une nouvelle tentative peut être ouverte (jours).',
                rules: ['value' => ['required', 'min:1', 'max:90']]),
            new SettingDefinition('payment.attempt_expiry_minutes', 'payment', SettingType::Integer, 60,
                'Au-delà de ce délai, une tentative de paiement non terminée est considérée comme expirée et une nouvelle peut être ouverte (minutes).',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:5', 'max:1440']]),
            new SettingDefinition('payment.webhook_tolerance_seconds', 'payment', SettingType::Integer, 300,
                'Écart maximal toléré entre l\'horodatage signé d\'un webhook et l\'heure du serveur (secondes) ; 0 désactive le contrôle.',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:0', 'max:3600']]),
            new SettingDefinition('payment.methods', 'payment', SettingType::Json, [
                ['code' => 'TMONEY', 'label' => 'T-Money', 'channel' => 'mobile_money', 'enabled' => true],
                ['code' => 'FLOOZ', 'label' => 'Flooz', 'channel' => 'mobile_money', 'enabled' => true],
                ['code' => 'BANK_TRANSFER', 'label' => 'Virement bancaire', 'channel' => 'manual', 'enabled' => true],
                ['code' => 'CASH', 'label' => 'Espèces', 'channel' => 'manual', 'enabled' => true],
            ], 'Moyens de paiement des contre-valeurs.', rules: [
                'value' => ['required', 'min:1'],
                'value.*.code' => ['required', 'string', 'alpha_dash:ascii', 'distinct'],
                'value.*.label' => ['required', 'string', 'max:60'],
                'value.*.channel' => ['required', 'in:mobile_money,manual'],
                'value.*.enabled' => ['required', 'boolean'],
            ]),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function yem(): array
    {
        return [
            new SettingDefinition('yem.cect_pernum', 'yem', SettingType::String, null,
                'Pernum de réception de la CECT dans l\'écosystème YEM.', SettingEditLevel::Superadmin,
                rules: ['value' => ['nullable', 'max:64']]),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function otp(): array
    {
        return [
            new SettingDefinition('otp.ttl_seconds', 'otp', SettingType::Integer, 300,
                'Durée de validité d\'un code OTP envoyé par email (secondes).', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'min:60', 'max:1800']]),
            new SettingDefinition('otp.max_attempts', 'otp', SettingType::Integer, 5,
                'Nombre maximal de tentatives de saisie par code OTP.', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'min:1', 'max:10']]),
            new SettingDefinition('otp.resend_cooldown_seconds', 'otp', SettingType::Integer, 60,
                'Délai minimal avant le renvoi d\'un code OTP (secondes).', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'min:0']]),
            new SettingDefinition('otp.max_sends_per_hour', 'otp', SettingType::Integer, 5,
                'Nombre maximal de codes OTP envoyés par heure et par compte.', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'min:1']]),
            new SettingDefinition('otp.required_on_member_login', 'otp', SettingType::Boolean, false,
                'Exiger un code OTP email à la connexion des Utilisateurs (toujours exigé pour les rôles internes).',
                SettingEditLevel::Superadmin),
            new SettingDefinition('otp.activation_ttl_seconds', 'otp', SettingType::Integer, 86400,
                'Durée de validité du code d\'activation envoyé à un nouveau compte interne (secondes).',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:600', 'max:604800']]),
            new SettingDefinition('otp.step_up_validity_seconds', 'otp', SettingType::Integer, 600,
                'Durée pendant laquelle une confirmation OTP (step-up) autorise une action sensible (secondes).',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:60', 'max:3600']]),
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function platform(): array
    {
        return [
            new SettingDefinition('platform.demo_gateway_bypass', 'platform', SettingType::Boolean, false,
                'Mode démonstration : activation de compte sans passerelle (DEMO_GATEWAY_BYPASS). Impossible en production.',
                SettingEditLevel::Superadmin, productionForbiddenValues: [true]),
        ];
    }
}