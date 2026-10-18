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
            ...$this->publicContent(),
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
                'Frais d\'adhésion initiale d\'une personne physique (XOF).', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'integer', 'min:0']]),
            new SettingDefinition('membership.fee.legal_person', 'membership', SettingType::Integer, 200000,
                'Frais d\'adhésion initiale d\'une personne morale (XOF).', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'integer', 'min:0']]),
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
            new SettingDefinition('rates.purchase_xof_per_yem', 'rates', SettingType::Integer, 510,
                'Cours d\'achat d\'un YEM par la CECT (XOF) — valeur paramétrable par le Superadmin.', SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:1']]),
            new SettingDefinition('rates.sale_xof_per_yem', 'rates', SettingType::Integer, 650,
                'Cours de vente d\'un YEM par la CECT (XOF) — à ratifier par le CA (PO-01).', SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:1']]),
            new SettingDefinition('rates.transfer_xof_per_yem', 'rates', SettingType::Integer, 610,
                'Cours de cession d\'un YEM (XOF) — à ratifier par le CA (PO-01).', SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:1']]),
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
            new SettingDefinition('cashback.rules', 'packs', SettingType::Json, [
                'enabled' => false,
                'rate_bps' => 0,
                'max_yem' => null,
                'families' => ['CONSOMMATEUR', 'PERSONNEL'],
                'rounding' => 'down',
            ], 'Cashback en YEM offert lors de l’achat (le prix payé ne change jamais) : activation, taux en centièmes de %, plafond en YEM (null = aucun), uniquement les packs Consommateur et Personnel, et arrondi. Chaque modification crée une version ; les achats déjà calculés gardent la leur.',
                SettingEditLevel::Superadmin, rules: [
                    'value' => ['required'],
                    'value.enabled' => ['required', 'boolean'],
                    'value.rate_bps' => ['required', 'integer', 'min:0', 'max:9999'],
                    'value.max_yem' => ['nullable', 'numeric', 'min:0'],
                    'value.families' => ['present', 'array'],
                    'value.families.*' => ['string', 'in:CONSOMMATEUR,PERSONNEL', 'distinct'],
                    'value.rounding' => ['required', 'in:down,half_up'],
                ]),
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
     * Public website content managed by the cooperative from the back-office.
     *
     * @return list<SettingDefinition>
     */
    private function publicContent(): array
    {
        return [
            new SettingDefinition('content.key_figures', 'content', SettingType::Json, [
                ['key' => 'members', 'value' => '100+', 'label_fr' => 'membres actifs', 'label_en' => 'active members'],
                ['key' => 'years', 'value' => '1', 'label_fr' => "année d'existence", 'label_en' => 'year of existence'],
                ['key' => 'packs', 'value' => '6', 'label_fr' => 'packs disponibles', 'label_en' => 'packs available'],
                ['key' => 'solidarity', 'value' => '100 %', 'label_fr' => 'engagement solidaire', 'label_en' => 'solidarity commitment'],
            ], 'Chiffres clés affichés sur le site public.', rules: [
                'value' => ['present', 'array', 'max:20'],
                'value.*.key' => ['required', 'string', 'max:40', 'distinct'],
                'value.*.value' => ['required', 'string', 'max:40'],
                'value.*.label_fr' => ['required', 'string', 'max:120'],
                'value.*.label_en' => ['required', 'string', 'max:120'],
            ]),
            new SettingDefinition('content.faq', 'content', SettingType::Json, [
                [
                    'id' => 'faq-cect',
                    'question_fr' => "Qu'est-ce que la CECT ?",
                    'answer_fr' => "La Coopérative d'Entraide Communautaire du Togo (CECT) est une coopérative qui organise l'entraide, les échanges et les services de ses membres autour du YEM.",
                    'question_en' => 'What is CECT?',
                    'answer_en' => 'CECT is a Togolese community mutual-aid cooperative organising member services and exchanges around YEM.',
                    'published' => true,
                ],
                [
                    'id' => 'faq-join',
                    'question_fr' => 'Qui peut adhérer à la CECT ?',
                    'answer_fr' => "Les personnes physiques et morales peuvent adhérer selon les conditions et frais d'adhésion en vigueur.",
                    'question_en' => 'Who can join CECT?',
                    'answer_en' => 'Natural persons and legal entities may join under the current membership conditions and fees.',
                    'published' => true,
                ],
                [
                    'id' => 'faq-yem',
                    'question_fr' => "Qu'est-ce que le YEM ?",
                    'answer_fr' => 'Le YEM circule dans un système externe à la plateforme CECT. La plateforme assure le suivi, le contrôle et la traçabilité des opérations déclarées par les membres.',
                    'question_en' => 'What is YEM?',
                    'answer_en' => 'YEM circulates in a system external to the CECT platform. The platform tracks, controls and records member operations.',
                    'published' => true,
                ],
            ], 'Questions fréquentes publiées sur le site public.', rules: [
                'value' => ['present', 'array', 'max:100'],
                'value.*.id' => ['required', 'string', 'max:80', 'distinct'],
                'value.*.question_fr' => ['required', 'string', 'max:240'],
                'value.*.answer_fr' => ['required', 'string', 'max:3000'],
                'value.*.question_en' => ['nullable', 'string', 'max:240'],
                'value.*.answer_en' => ['nullable', 'string', 'max:3000'],
                'value.*.published' => ['required', 'boolean'],
            ]),
            new SettingDefinition('content.news', 'content', SettingType::Json, [
                [
                    'id' => 'a1',
                    'slug' => 'lancement-site-refonte',
                    'title_fr' => 'Lancement du nouveau site CECT',
                    'title_en' => 'Launch of the new CECT website',
                    'excerpt_fr' => 'La CECT annonce le lancement de sa nouvelle plateforme web.',
                    'excerpt_en' => 'CECT announces the launch of its new web platform.',
                    'content_fr' => "La nouvelle plateforme CECT centralise l'adhésion, les packs, les opérations et leur traçabilité.",
                    'content_en' => 'The new CECT platform centralises membership, packs, operations and traceability.',
                    'date' => '2026-10-04',
                    'category' => 'announcement',
                    'published' => true,
                ],
            ], 'Actualités du site public.', rules: [
                'value' => ['present', 'array', 'max:200'],
                'value.*.id' => ['required', 'string', 'max:80', 'distinct'],
                'value.*.slug' => ['required', 'string', 'max:120', 'distinct'],
                'value.*.title_fr' => ['required', 'string', 'max:240'],
                'value.*.title_en' => ['nullable', 'string', 'max:240'],
                'value.*.excerpt_fr' => ['required', 'string', 'max:1000'],
                'value.*.excerpt_en' => ['nullable', 'string', 'max:1000'],
                'value.*.content_fr' => ['required', 'string', 'max:10000'],
                'value.*.content_en' => ['nullable', 'string', 'max:10000'],
                'value.*.date' => ['required', 'date'],
                'value.*.category' => ['required', 'string', 'max:60'],
                'value.*.published' => ['required', 'boolean'],
            ]),
            new SettingDefinition('content.partners', 'content', SettingType::Json, [], 'Partenaires institutionnels/commerciaux affichés sur le site public.', rules: [
                'value' => ['present', 'array', 'max:300'],
                'value.*.id' => ['required', 'string', 'max:80', 'distinct'],
                'value.*.name' => ['required', 'string', 'max:180'],
                'value.*.category' => ['required', 'string', 'max:60'],
                'value.*.city' => ['required', 'string', 'max:100'],
                'value.*.address' => ['nullable', 'string', 'max:300'],
                'value.*.phone' => ['nullable', 'string', 'max:40'],
                'value.*.description_fr' => ['nullable', 'string', 'max:1200'],
                'value.*.description_en' => ['nullable', 'string', 'max:1200'],
                'value.*.published' => ['required', 'boolean'],
            ]),
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
            new SettingDefinition('payout.countries', 'payout', SettingType::Json, [
                ['code' => 'TG', 'name' => 'Togo', 'dial_code' => '228', 'national_lengths' => [8], 'enabled' => true],
                ['code' => 'BJ', 'name' => 'Bénin', 'dial_code' => '229', 'national_lengths' => [8, 10], 'enabled' => true],
                ['code' => 'CI', 'name' => 'Côte d’Ivoire', 'dial_code' => '225', 'national_lengths' => [10], 'enabled' => true],
                ['code' => 'SN', 'name' => 'Sénégal', 'dial_code' => '221', 'national_lengths' => [9], 'enabled' => true],
                ['code' => 'BF', 'name' => 'Burkina Faso', 'dial_code' => '226', 'national_lengths' => [8], 'enabled' => true],
                ['code' => 'ML', 'name' => 'Mali', 'dial_code' => '223', 'national_lengths' => [8], 'enabled' => true],
                ['code' => 'NE', 'name' => 'Niger', 'dial_code' => '227', 'national_lengths' => [8], 'enabled' => true],
            ], 'Pays où un membre peut recevoir ses fonds : indicatif téléphonique, longueurs de numéro national acceptées et activation. Le numéro saisi est normalisé en E.164 puis validé avec ces règles.',
                SettingEditLevel::Superadmin, rules: [
                    'value' => ['required'],
                    'value.*.code' => ['required', 'string', 'size:2', 'alpha:ascii'],
                    'value.*.name' => ['required', 'string', 'max:80'],
                    'value.*.dial_code' => ['required', 'string', 'regex:/^[1-9][0-9]{0,3}$/'],
                    'value.*.national_lengths' => ['required', 'array', 'min:1'],
                    'value.*.national_lengths.*' => ['integer', 'min:4', 'max:12'],
                    'value.*.enabled' => ['required', 'boolean'],
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
        $simulationEnvironment = app()->environment(['local', 'testing', 'staging']);
        $providerDefault = $simulationEnvironment ? 'fake' : 'fedapay';
        $methodSuffix = $simulationEnvironment ? ' (simulation)' : '';

        return [
            new SettingDefinition('payment.inbound_provider', 'payment', SettingType::String, $providerDefault,
                'Moteur technique des encaissements. En staging, seul le simulateur interne est utilisable.', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'in:fake,fedapay']], productionForbiddenValues: ['fake']),
            new SettingDefinition('payment.payout_provider', 'payment', SettingType::String, $providerDefault,
                'Moteur technique des décaissements. En staging, seul le simulateur interne est utilisable.', SettingEditLevel::Superadmin,
                rules: ['value' => ['required', 'in:fake,fedapay']], productionForbiddenValues: ['fake']),
            new SettingDefinition('payment.customer_bears_fees', 'payment', SettingType::Boolean, false,
                'Frais techniques du provider. Ils restent désactivés en recette tant qu’aucun contrat réel n’est activé.',
                SettingEditLevel::Superadmin),
            new SettingDefinition('payment.customer_fees', 'payment', SettingType::Json, [
                'fake' => ['rate_bps' => 0, 'fixed_xof' => 0, 'rounding_xof' => 1],
                'manual' => ['rate_bps' => 0, 'fixed_xof' => 0, 'rounding_xof' => 1],
            ], 'Barème technique par provider. Aucun frais réel n’est appliqué par défaut en recette.',
                SettingEditLevel::Superadmin, rules: [
                    'value' => ['required'],
                    'value.*.rate_bps' => ['required', 'integer', 'min:0', 'max:5000'],
                    'value.*.fixed_xof' => ['required', 'integer', 'min:0'],
                    'value.*.rounding_xof' => ['sometimes', 'integer', 'min:1', 'max:1000'],
                ]),
            new SettingDefinition('payment.collection_modes', 'payment', SettingType::Json,
                \App\Domain\Payment\AfricanMobileMoneyCatalog::mobileCollectionModes(),
                'Routage de test des opérateurs mobiles et règlements manuels (aucun débit réel).',
                rules: ['value' => ['required'], 'value.*' => ['in:psp,manual']]),
            new SettingDefinition('payment.manual_instructions', 'payment', SettingType::Json,
                $simulationEnvironment ? [
                    'BANK_TRANSFER' => 'SIMULATION UNIQUEMENT — aucun virement réel. Saisissez une référence de test pour transmettre cette tentative à Comptabilité.',
                    'CASH' => 'SIMULATION UNIQUEMENT — aucun encaissement réel. Saisissez une référence de test pour transmettre cette tentative à Comptabilité.',
                ] : [],
                'Instructions du workflow manuel. Elles doivent être configurées et ratifiées avant tout usage réel.',
                rules: ['value' => ['present', 'array'], 'value.*' => ['string', 'max:500']]),
            new SettingDefinition('payment.manual_declaration_ttl_days', 'payment', SettingType::Integer, 7,
                'Délai d’expiration d’une tentative manuelle non déclarée/non validée (jours).',
                rules: ['value' => ['required', 'min:1', 'max:90']]),
            new SettingDefinition('payment.attempt_expiry_minutes', 'payment', SettingType::Integer, 60,
                'Durée maximale d’une tentative provider/simulateur non terminée (minutes).',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:5', 'max:1440']]),
            new SettingDefinition('payment.webhook_tolerance_seconds', 'payment', SettingType::Integer, 300,
                'Écart maximal toléré entre l’horodatage signé d’un webhook et l’heure serveur (secondes).',
                SettingEditLevel::Superadmin, rules: ['value' => ['required', 'min:0', 'max:3600']]),
            new SettingDefinition('payment.methods', 'payment', SettingType::Json,
                \App\Domain\Payment\AfricanMobileMoneyCatalog::methods($simulationEnvironment),
                'Simulation Mobile Money : pays documentés BCEAO/GSMA, sans contrat de paiement réel.', rules: [
                'value' => ['required', 'min:1'],
                'value.*.code' => ['required', 'string', 'alpha_dash:ascii', 'distinct'],
                'value.*.label' => ['required', 'string', 'max:80'],
                'value.*.channel' => ['required', 'in:mobile_money,card,manual'],
                'value.*.countries' => ['sometimes', 'array', 'max:250'],
                'value.*.countries.*' => ['required', 'string', 'size:2', 'alpha:ascii'],
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
