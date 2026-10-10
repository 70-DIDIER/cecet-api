# API CECT / YEM

API de la plateforme de la coopérative CECT : adhésions, packs (Vendeur, Consommateur, Personnel, Marchand), quotas YEM, ventes et rééchanges à la CECT, paiements marchands par QR, versements en FCFA, validations, comptabilité et reporting.

**Laravel 13 · PHP 8.3 · PostgreSQL 18** — API seule (pas d'interface dans ce dépôt). Spécification : `Cahier_des_charges_CECT_YEM_final_v4.0.docx`.

## Principes

- **Tout est configurable par le Superadmin** : taux, frais, packs et leurs versions, moyens de paiement, seuils, durées, notifications… (paramètres versionnés, motif obligatoire). Aucune règle économique n'est codée en dur.
- **Intégrité avant tout** : journaux de quota et de solde marchand en ajout seul (triggers PostgreSQL), journal d'audit chaîné par hachage, contraintes `CHECK`, verrous de ligne (pas de double dépense), idempotence des opérations financières, séparation des tâches (maker-checker).
- **Les frais de transaction sont à la charge du membre** ; la coopérative perçoit le montant net.
- **Codes OTP par e-mail** (connexion du personnel, vérification, actions sensibles).

## Documentation

| Document | Pour qui |
|---|---|
| [`docs/guide-integration-frontend.md`](docs/guide-integration-frontend.md) | équipe frontend : conventions, authentification, parcours, erreurs, index des routes |
| [`docs/openapi.json`](docs/openapi.json) | contrat OpenAPI 3 (Postman, générateurs de client) |
| [`docs/sauvegarde-et-restauration.md`](docs/sauvegarde-et-restauration.md) | exploitation : sauvegarde, test de restauration, reprise après sinistre |
| `/docs/api` | documentation interactive (Scramble), en développement |

Régénérer le contrat après toute évolution de l'API : `php artisan scramble:export --path=docs/openapi.json`.

## Installation

Prérequis : PHP 8.3 (extensions `pdo_pgsql`, `zip`, `sodium`), Composer, PostgreSQL.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Renseigner dans `.env` la connexion PostgreSQL (`DB_*`), puis :

```bash
php artisan migrate
php artisan settings:sync                    # initialise les paramètres jamais configurés
php artisan db:seed --class=AccessSeeder     # rôles et permissions
php artisan cect:create-superadmin           # premier Superadmin (code d'activation par e-mail)
php artisan db:seed --class=LegacyCatalogSeeder   # optionnel : grille de l'ancien site, en brouillon
php artisan serve
```

La grille de packs est chargée en **brouillon** : rien n'est proposé aux membres tant que la coopérative n'a pas relu et publié chaque pack depuis le back-office.

### Comptes de démonstration (développement et recette uniquement)

```bash
php artisan db:seed --class=DemoClientSeeder   # refuse de s'exécuter en production
```

Le seeder crée un historique complet (adhésion, packs, ventes, paiements marchands, rechargement). Le mot de passe commun vient de `DEMO_PASSWORD` et l'e-mail du client de `DEMO_CLIENT_EMAIL` (`config/demo.php`).

| Compte | Identifiant | Mot de passe |
|---|---|---|
| Client (membre, personne physique) | `tandemodon55@gmail.com` ou Pernum `1009000055` | `Demo2026cect` |
| Marchand (personne morale, Pernum `1009000077`) | `marchand.demo@cect.example` | `Demo2026cect` |
| Personnel (Superadmin), back-office `/backoffice` | `superadmin.demo@cect.example` | `Demo2026cect` |

- Pernum de réception de la CECT (démonstration) : `1000000001`.
- La connexion du personnel demande un code OTP envoyé par e-mail : avec `MAIL_MAILER=log`, le code se lit dans `storage/logs/laravel.log`.
- Si le compte client existe déjà, son mot de passe n'est pas modifié.
- Ces identifiants ne doivent jamais exister en production.

### Variables d'environnement utiles

| Variable | Rôle |
|---|---|
| `APP_URL` | adresse publique de l'API (utilisée aussi dans l'adresse encodée par les QR marchands) |
| `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, `SESSION_DOMAIN` | domaines du site autorisés à utiliser les cookies de session |
| `MAIL_MAILER` | `log` en développement : les codes OTP sont lus dans `storage/logs/laravel.log` |
| `QUEUE_CONNECTION` | `database` (jamais `sync` en production) |
| `PAYMENT_RETURN_URL` | page de retour du site après un paiement hébergé |
| `FEDAPAY_ENVIRONMENT`, `FEDAPAY_SECRET_KEY`, `FEDAPAY_WEBHOOK_SECRET` | prestataire de paiement (l'adaptateur n'a pas encore été éprouvé contre le sandbox réel) |

Le prestataire de paiement et celui des versements se choisissent dans les paramètres (`payment.inbound_provider`, `payment.payout_provider`). **FedaPay est le prestataire par défaut hors tests.** Le prestataire `fake` est strictement réservé aux tests automatisés : il n'est résolu ni en recette/staging ni en production, et les routes de démonstration ne sont pas exposées dans ces environnements.

## Exécution

```bash
php artisan serve                 # API
php artisan queue:work            # e-mails, webhooks de paiement, versements
php artisan schedule:work         # tâches planifiées (voir ci-dessous)
```

Tâches planifiées (`routes/console.php`) : crédit des périodes de packs, annulation des packs impayés, relais de l'outbox, rafraîchissement des versements, expiration des réservations (ventes et paiements marchands), vérification des journaux et de la chaîne d'audit, snapshots consolidés, supervision et alertes.

## Commandes d'exploitation

| Commande | Rôle |
|---|---|
| `php artisan ops:check [--integrity]` | santé de la plateforme et alertes (e-mail aux adresses de `ops.alert_emails`) |
| `php artisan ops:golive` | contrôle de la configuration avant mise en production |
| `php artisan ledger:verify` | recalcule chaque compte de quota et de solde marchand depuis ses écritures |
| `php artisan audit:verify` | vérifie la chaîne d'intégrité du journal d'audit |
| `php artisan snapshots:generate [--force]` | snapshot consolidé (ZIP de CSV + manifeste et empreinte) |
| `php artisan payouts:refresh` | réconcilie les versements en cours avec le prestataire |
| `php artisan outbox:relay` | délivre les messages en attente de la boîte d'envoi transactionnelle |
| `php artisan payments:fake-settle <tentative>` | simulation réservée aux tests automatisés ; indisponible en recette/staging et en production |

Une divergence signalée par `ledger:verify` ou `audit:verify` est un incident critique : geler les versements jusqu'à explication.

## Tests et qualité

Les tests s'exécutent sur un **vrai PostgreSQL** (base `cectapi_test`, à créer une fois), car les verrous et la concurrence font partie des garanties testées.

```bash
php artisan test --compact --testsuite=Feature,Unit   # suite principale (~5 min)
php artisan test --compact --testsuite=Concurrency    # vrais processus PHP en parallèle (~1 min)
vendor/bin/pint                                       # style
vendor/bin/phpstan analyse --memory-limit=2G          # analyse statique (Larastan, niveau 8)
composer audit                                        # vulnérabilités des dépendances
```

## Architecture

Monolithe modulaire sous `app/Domain/` : chaque module regroupe ses modèles, actions, contrôleurs, ressources et commandes. Les contrôleurs sont minces ; chaque opération métier passe par une *Action* qui, dans une seule transaction, contrôle l'accès, verrouille, écrit les journaux, change l'état et enregistre l'audit.

| Module | Rôle |
|---|---|
| `Shared` | idempotence, identifiant de corrélation, format d'erreur, en-têtes de sécurité, outbox |
| `Settings` | catalogue de paramètres versionnés (`SettingsCatalog`) |
| `Audit` | journal d'audit chaîné et journal de sécurité |
| `Identity`, `Access` | comptes, Pernum, OTP e-mail, sessions, rôles et permissions |
| `Membership`, `Payment` | adhésion, paiements entrants (prestataire ou manuels), webhooks, validation manuelle |
| `Catalog`, `Subscription`, `Quota` | packs versionnés, souscriptions, périodes, journal de quota, rechargements |
| `Exchange`, `Payout` | vente et rééchange de YEM à la CECT, validation, versements en FCFA |
| `Merchant` | QR marchands, paiements marchands, solde marchand, annuaire |
| `Reporting` | rapports, exports CSV, snapshots, réimports contrôlés |
| `Notification`, `Ops` | e-mails d'information, supervision, contrôle de mise en production |

Rôles internes : `superadmin`, `administrateur`, `validateur`, `comptabilite` (les permissions sont éditables par le Superadmin, sauf invariants de séparation des tâches).

## Mise en production

1. Renseigner un `.env` de production (HTTPS, `APP_DEBUG=false`, vrai service d'e-mail, file d'attente, cookies sécurisés, clés du prestataire en mode `live`).
2. Lancer `php artisan ops:golive` : chaque point bloquant doit disparaître.
3. Vérifier que la sauvegarde et un test de restauration documenté existent ([procédure](docs/sauvegarde-et-restauration.md)).
4. Les décisions de gouvernance (taux, remises, packs publiés, durées) relèvent de la coopérative : elles se saisissent dans le back-office, pas dans le code.
