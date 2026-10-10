# CECT YEM — Backend / DevOps / Testing Handoff

**Version spec :** Cahier des charges CECT YEM v4.0  
**Date :** 2026-10-09  
**Scope :** Tout ce qui **n'est pas du ressort du frontend** React/TypeScript.  
Les UI sont terminées côté client et admin ; les sections ci-dessous listent ce qui reste à faire en Laravel/backend, infrastructure, et tests.

---

## 1. Backend (Laravel + Sanctum)

### 1.1 Authentification & sessions

| Tâche | Détail |
|-------|--------|
| OTP SMS/email | Endpoint `POST /auth/register` génère un `challenge_id` ; `POST /auth/otp/verify` valide le code à 6 chiffres. Délai d'expiration 10 min, max 3 tentatives avant blocage temporaire. |
| Renouvellement OTP | `POST /auth/otp/resend` avec rate-limit (1 renvoi / 60 s). |
| Session management | `GET /auth/sessions` — liste les sessions actives avec IP, user-agent, date. `DELETE /auth/sessions/{id}` révoque une session individuelle. `DELETE /auth/sessions` révoque toutes les autres sessions. |
| MFA TOTP | `POST /auth/mfa/setup` génère le secret TOTP + URI QR. `POST /auth/mfa/enable` confirme avec le premier code. `POST /auth/mfa/verify` requis lors de la connexion si MFA activé. `DELETE /auth/mfa` désactive (mot de passe requis). |
| Statuts de compte | Gérer les transitions : `PENDING_PAYMENT` → `PENDING_ACTIVATION` → `ACTIVE` → `SUSPENDED`. Les routes `/me/*` doivent répondre 403 si `PENDING_PAYMENT` sauf `GET /me/profile`. |

### 1.2 Inscription & membres

| Tâche | Détail |
|-------|--------|
| Champ `pernum_business` | Enregistrer le Pernum Business pour les personnes morales (table `users` ou `user_profiles`). Valider unicité + format `[A-Za-z0-9._-]{3,64}`. |
| Attribution N° membre | Séquence auto (ex. `CECT-2026-00001`). |
| Pernum individuel | Auto-généré ou saisi ; valider unicité globale. |
| Vérification adhésion | Après paiement confirmé par admin, passer le compte à `ACTIVE` + envoyer e-mail de bienvenue. |
| `GET /me/profile` | Retourner `pernum_business` pour les personnes morales dans la réponse. |
| `PUT /me` | Accepter les champs éditables : `first_name`, `last_name`, `phone`, `city`. Rejeter toute tentative de modifier `pernum`, `email`, `person_type`. |
| `GET /me/dashboard` | Agréger : `yem` (balance, reserved, total_credited, total_spent), `membership`, `active_packs[]`, `recent_movements[]`, `payments_awaiting_action[]`. |

### 1.3 Packs

| Tâche | Détail |
|-------|--------|
| Catalogue CRUD (admin) | `GET /admin/packs` / `POST /admin/packs` / `PATCH /admin/packs/{id}` / `DELETE`. États : `DRAFT → PUBLISHED → SUSPENDED → RETIRED → ARCHIVED`. Versionning (nouveau pack = nouvelle version, anciens membres gardent l'ancienne). |
| Souscription | `POST /me/packs/subscribe` avec `pack_code`. Vérifier : max 2 packs structurés actifs, `forLegal` uniquement pour personnes morales, compte `ACTIVE`. |
| Champ `total_credited` / `total_spent` sur les packs actifs | Agréger depuis la table des mouvements et retourner dans `active_packs[]`. |
| Expiration | Job planifié : vérifier `expires_at` chaque nuit, passer les packs expirés à `EXPIRED`, notifier le membre. |

### 1.4 Workflows YEM — machine à états (§12 du CdC)

Implémenter la machine à états pour chaque opération YEM :

```
REQUEST_CREATED
  → AWAITING_YEM_TRANSFER      (admin ou auto : demande créée)
  → USER_DECLARED_SENT         (membre : "j'ai envoyé")
  → VERIFYING                  (validateur : ouvre le dossier)
  → YEM_RECEIVED               (validateur : confirme réception + quantité réelle)
  → COUNTERVALUE_PENDING       (finance : à payer)
  → COMPLETED                  (finance : paiement enregistré)
  → CANCELLED                  (depuis REQUEST_CREATED ou AWAITING_YEM_TRANSFER)
  → DISPUTED                   (depuis VERIFYING ou YEM_RECEIVED)
```

| Endpoint | Rôle | Description |
|----------|------|-------------|
| `POST /me/yem/sell` | Membre | Crée une demande de vente (REQUEST_CREATED) |
| `POST /me/yem/sell/{id}/declare` | Membre | Déclare l'envoi YEM (→ USER_DECLARED_SENT) |
| `POST /me/yem/sell/{id}/cancel` | Membre | Annule (si encore dans AWAITING_YEM_TRANSFER) |
| `GET /me/yem/transactions` | Membre | Historique complet paginé |
| `POST /validations/{id}/confirm` | Admin validateur | Confirme réception + quantité réelle (→ YEM_RECEIVED) |
| `POST /validations/{id}/reject` | Admin validateur | Rejette avec motif (→ CANCELLED) |
| `POST /validations/{id}/dispute` | Admin validateur | Ouvre un litige (→ DISPUTED) |
| `GET /validations/pending` | Admin validateur | File d'attente des dossiers à traiter |

### 1.5 Paiements sortants (payouts — §7.2 & §8)

| Tâche | Détail |
|-------|--------|
| Génération payout | À l'état YEM_RECEIVED, créer automatiquement un enregistrement payout : `user_id`, `yem_quantity`, `countervalue_xof` (qty × 650), `method` (null), `status: CREATED`. |
| Éligibilité auto | Si le membre a un RIB vérifié ET un historique > seuil configurable → passer à `APPROVED_AUTO`. |
| `GET /finance/payouts` | Admin comptabilité : liste tous les payouts avec filtres (statut, méthode, date). |
| `POST /finance/payouts/{id}/settle` | Enregistre le règlement manuel (T-Money, Flooz, virement, espèces) + preuve optionnelle. Passe à `SUCCEEDED`. |
| `POST /finance/payouts/{id}/retry` | Relance un payout `FAILED`. |
| YEM column (read-only) | La colonne "YEM confirmé" est en lecture seule côté admin finance — renseignée par le validateur. |

### 1.6 Paiements reçus (merchantpayments — §6.2)

| Tâche | Détail |
|-------|--------|
| `GET /merchant-payments/qr/{code}` | Résoudre un code QR/Pernum marchand → retourner `{ merchant: { name, outlet_label }, pernum }`. |
| `POST /merchant-payments` | Membre paye un marchand : `{ pack_id, merchant_pernum, quantity }`. Vérifier quota disponible, débiter le pack, notifier le marchand. |
| Confirmation marchand | `POST /merchant-payments/{id}/confirm` (côté marchand) — confirme la réception YEM. |

### 1.7 Notifications (§5.4)

| Tâche | Détail |
|-------|--------|
| Système de notifications en base | Table `notifications` : `user_id`, `type`, `payload`, `read_at`. |
| `GET /me/notifications` | Liste les notifications non lues. |
| `PATCH /me/notifications/{id}/read` | Marque comme lu. |
| Types à émettre | `QUOTA_LOW` (< 20 % restant), `PACK_EXPIRING` (J-7), `PAYMENT_VALIDATED`, `PAYMENT_PENDING`, `DISPUTE_OPENED`, `MEMBERSHIP_ACTIVATED`. |
| Push / email | Optionnel V1 : envoyer un e-mail transactionnel via Mailgun/SES pour les types critiques. |

### 1.8 Paramètres système (§4.3)

| Tâche | Détail |
|-------|--------|
| Table `settings` | `key` (unique), `value`, `updated_by`, `updated_at`. |
| `GET /admin/settings` | Retourne toutes les clés. |
| `PUT /admin/settings/{key}` | Met à jour une valeur. Clés : `yem_buy_price`, `yem_sell_price`, `yem_transfer_price`, `membership_individual`, `membership_company`, `discount_min_bps`, `discount_max_bps`, `max_active_structured_packs`, `cect_pernum_destination`. |
| Validation | Les taux YEM doivent vérifier : `buy_price < sell_price`, `transfer_price < sell_price`. |

### 1.9 Documents & exports (§10)

| Tâche | Détail |
|-------|--------|
| `GET /me/documents` | Liste les documents disponibles pour le membre (fiche d'adhésion PDF, reçu, relevé). |
| `GET /me/documents/{id}/download` | Téléchargement signé (URL temporaire S3 ou génération PDF à la volée). |
| `POST /admin/exports` | Déclenche la génération d'un export : `{ type, format: "xlsx"|"pdf", date_from, date_to }`. Types : `adhesions`, `packs_actifs`, `quotas`, `ventes_cessions`, `paiements`, `audit`. |
| `GET /admin/exports` | Liste les exports générés récemment avec URL de téléchargement. |
| Génération PDF | Utiliser Laravel Snappy ou DomPDF. Fiche d'adhésion avec signature électronique CECT. |

### 1.10 Gestion admin des membres (§9)

| Tâche | Détail |
|-------|--------|
| `GET /admin/members` | Liste paginée avec filtres (`status`, `person_type`, `search`). |
| `GET /admin/members/{id}` | Profil complet : infos, packs, historique YEM, paiements. |
| `POST /admin/members/{id}/suspend` | Suspendu avec motif. Invalide toutes les sessions actives du membre. |
| `POST /admin/members/{id}/reactivate` | Réactivation avec note. |
| `POST /admin/members/{id}/activate` | Activation depuis `PENDING_ACTIVATION` (après vérification paiement). |

---

## 2. Infrastructure & DevOps

### 2.1 Environnements

| Environnement | URL frontend | URL admin | API |
|---------------|-------------|-----------|-----|
| Dev | localhost:5173 | localhost:5174 | localhost:8000 |
| Staging | staging.cecttogo.org | admin-staging.cecttogo.org | api-staging.cecttogo.org |
| Production | cecttogo.org | admin.cecttogo.org | api.cecttogo.org |

### 2.2 CI/CD

- [ ] **Pipeline CI** : lint + typecheck + build sur chaque PR (GitHub Actions ou GitLab CI).
- [ ] **Pipeline CD** : déploiement automatique sur staging à chaque merge sur `main`.
- [ ] **Tests Laravel** : `php artisan test` dans la CI avant chaque déploiement.
- [ ] **Migrations** : `php artisan migrate --force` en pré-déploiement (jamais de rollback automatique en prod).
- [ ] **Rollback plan** : documenter la procédure de rollback si une migration échoue.

### 2.3 Serveur & hébergement

- [ ] **HTTPS obligatoire** sur tous les domaines (Let's Encrypt ou certificat wildcard).
- [ ] **CORS** : restreindre `SANCTUM_STATEFUL_DOMAINS` et `CORS_ALLOWED_ORIGINS` aux domaines listés ci-dessus. Ne jamais utiliser `*`.
- [ ] **Rate limiting** : configurer les throttles Laravel pour les endpoints sensibles :
  - `/api/auth/login` : 5 req/min par IP
  - `/api/auth/otp/verify` : 3 req/min par `challenge_id`
  - `/api/auth/otp/resend` : 1 req/min par `challenge_id`
- [ ] **Queue worker** : démarrer `php artisan queue:work` en daemon (Supervisor). Files : `default`, `notifications`, `exports`.
- [ ] **Scheduler** : `php artisan schedule:run` via cron toutes les minutes (vérification expirations packs, rappels, purge tokens).
- [ ] **Stockage S3/compatible** : configurer le driver `s3` pour les documents membres et les exports. Ne jamais stocker de fichiers sensibles sur le disque local en prod.
- [ ] **Logs** : centraliser via Laravel Telescope (dev) + Sentry ou Papertrail (prod). Masquer les champs sensibles (`password`, `otp_code`) dans les logs.

### 2.4 Sécurité

- [ ] **Variables d'environnement** : ne jamais committer `.env.production`. Utiliser des secrets gérés (GitHub Secrets, Vault, ou variables CI/CD).
- [ ] **APP_KEY** : rotation planifiée (ne pas oublier de vider le cache session après rotation).
- [ ] **Sanctum** : vérifier que `SameSite=Lax` est bien positionné sur le cookie session en production (idem CSRF token).
- [ ] **Headers de sécurité** : configurer sur le serveur web (Nginx/Caddy) : `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`.
- [ ] **Audit trail** : logger chaque action admin (suspension membre, modification taux, validation YEM) avec `user_id`, `action`, `payload`, `ip`, `timestamp` dans une table `audit_logs` en écriture seule.
- [ ] **Backup base de données** : snapshots quotidiens chiffrés, rétention 30 jours, test de restauration mensuel.

### 2.5 Configuration Nginx (exemple de base)

```nginx
# A adapter selon le VPS/cloud utilisé
server {
    listen 443 ssl http2;
    server_name api.cecttogo.org;

    root /var/www/cect-api/public;
    index index.php;

    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}
```

---

## 3. Tests

### 3.1 Tests backend (PHPUnit / Pest)

#### Authentification
- [ ] Inscription personne physique → OTP envoyé → vérification → compte `PENDING_PAYMENT`
- [ ] Inscription personne morale → champs `pernum_business`, `legal_name`, `legal_form` persistés
- [ ] OTP expiré (> 10 min) → 422
- [ ] OTP dépassé 3 tentatives → 429 avec délai de blocage
- [ ] Connexion avec MFA activé → challenge MFA requis
- [ ] Accès route `/me/dashboard` sans authentification → 401
- [ ] Accès route `/me/dashboard` avec compte `SUSPENDED` → 403

#### Packs
- [ ] Souscription pack Vendeur → quota initialisé, `activated_at` renseigné
- [ ] Tentative de 3 packs structurés actifs → 422 (`MAX_ACTIVE_PACKS_REACHED`)
- [ ] Pack `forLegal=true` souscrit par personne physique → 422
- [ ] Pack expiré → job `ExpirePacksJob` le passe à `EXPIRED` + notif émise

#### Workflows YEM
- [ ] Flux complet vente : `POST /me/yem/sell` → `declare` → validateur `confirm` → payout créé → `settle` → état `COMPLETED`
- [ ] Tentative de vente sans pack Vendeur actif → 422
- [ ] Vente dépassant le quota disponible → 422
- [ ] Rejet validateur → état `CANCELLED`, membre notifié
- [ ] Litige → état `DISPUTED`, accessible depuis l'admin

#### Paiements marchands
- [ ] Lookup QR inexistant → 404 `MERCHANT_QR_INVALID`
- [ ] Paiement marchand sans pack Consommateur/Personnel → 422
- [ ] Paiement marchand avec quota insuffisant → 422

#### Paramètres
- [ ] `PUT /admin/settings/yem_buy_price` avec valeur supérieure à `yem_sell_price` → 422
- [ ] Seul un admin peut accéder aux routes `/admin/*` → 403 pour un membre normal

#### Exports
- [ ] Génération export `adhesions` CSV → fichier valide dans le storage
- [ ] Export PDF fiche d'adhésion → PDF contenant le nom et le numéro membre

### 3.2 Tests d'intégration frontend (Playwright ou Cypress)

- [ ] **Parcours inscription complet** : formulaire physique → OTP → compte créé
- [ ] **Parcours inscription personne morale** : champ Pernum Business présent + soumis
- [ ] **Connexion + accès espace membre** : tableau de bord se charge, KPIs affichés
- [ ] **Onglet Transactions** : liste vide → message "Aucune transaction" ; avec données → lignes affichées
- [ ] **Onglet Documents** : bouton "Télécharger" présent pour les docs disponibles
- [ ] **Paiement marchand** : saisie code → nom marchand affiché → bouton continuer
- [ ] **Admin ValidationsPage** : ligne dossier → clic expand → formulaire confirmer → soumission
- [ ] **Admin ConfigurationPage** : modifier taux YEM → sauvegarder → valeur relue

### 3.3 Tests de charge

- [ ] Simuler 500 utilisateurs simultanés sur `GET /me/dashboard` (résultat attendu : < 300 ms p95)
- [ ] Simuler 50 validations simultanées (race condition sur les statuts YEM)
- [ ] Exporter 10 000 lignes en CSV → pas de timeout (utiliser une queue)

### 3.4 Tests de sécurité (OWASP)

- [ ] **Injection SQL** : tous les inputs passent par Eloquent ou query builder avec bindings.
- [ ] **IDOR** : un membre ne peut pas accéder aux ressources d'un autre membre (`/me/yem/sell/{id}` vérifie `user_id`).
- [ ] **Brute-force** : rate limiting actif sur login et OTP.
- [ ] **CSRF** : vérifier que les requêtes POST depuis un domaine non autorisé sont rejetées.
- [ ] **Mass assignment** : tous les modèles Eloquent définissent `$fillable` (pas de `$guarded = []`).

---

## 4. Récapitulatif des endpoints à implémenter

```
Auth
  POST   /api/v1/auth/register
  POST   /api/v1/auth/otp/verify
  POST   /api/v1/auth/otp/resend
  POST   /api/v1/auth/login
  POST   /api/v1/auth/logout
  GET    /api/v1/auth/sessions
  DELETE /api/v1/auth/sessions/{id}
  DELETE /api/v1/auth/sessions
  POST   /api/v1/auth/mfa/setup
  POST   /api/v1/auth/mfa/enable
  POST   /api/v1/auth/mfa/verify
  DELETE /api/v1/auth/mfa

Membre
  GET    /api/v1/me/profile
  PUT    /api/v1/me
  GET    /api/v1/me/dashboard
  GET    /api/v1/me/notifications
  PATCH  /api/v1/me/notifications/{id}/read
  GET    /api/v1/me/documents
  GET    /api/v1/me/documents/{id}/download

Packs (membre)
  POST   /api/v1/me/packs/subscribe

YEM (membre)
  POST   /api/v1/me/yem/sell
  POST   /api/v1/me/yem/sell/{id}/declare
  POST   /api/v1/me/yem/sell/{id}/cancel
  GET    /api/v1/me/yem/transactions

Paiements marchands
  GET    /api/v1/merchant-payments/qr/{code}
  POST   /api/v1/merchant-payments
  POST   /api/v1/merchant-payments/{id}/confirm

Admin — Validations
  GET    /api/v1/validations/pending
  POST   /api/v1/validations/{id}/confirm
  POST   /api/v1/validations/{id}/reject
  POST   /api/v1/validations/{id}/dispute

Admin — Finance
  GET    /api/v1/finance/payouts
  POST   /api/v1/finance/payouts/{id}/settle
  POST   /api/v1/finance/payouts/{id}/retry

Admin — Membres
  GET    /api/v1/admin/members
  GET    /api/v1/admin/members/{id}
  POST   /api/v1/admin/members/{id}/activate
  POST   /api/v1/admin/members/{id}/suspend
  POST   /api/v1/admin/members/{id}/reactivate

Admin — Packs catalogue
  GET    /api/v1/admin/packs
  POST   /api/v1/admin/packs
  PATCH  /api/v1/admin/packs/{id}
  DELETE /api/v1/admin/packs/{id}

Admin — Paramètres
  GET    /api/v1/admin/settings
  PUT    /api/v1/admin/settings/{key}

Admin — Exports
  GET    /api/v1/admin/exports
  POST   /api/v1/admin/exports
  GET    /api/v1/admin/exports/{id}/download
```

---

## 5. Modèle de données (tables minimales)

| Table | Colonnes clés |
|-------|---------------|
| `users` | `id`, `member_number`, `person_type`, `pernum`, `pernum_business`, `legal_name`, `legal_form`, `status`, `mfa_enabled`, `mfa_secret` |
| `memberships` | `id`, `user_id`, `status`, `paid_at`, `member_since`, `fee_xof` |
| `packs_catalogue` | `id`, `code`, `family`, `name`, `price_xof`, `quota_per_period`, `discount_bps`, `for_legal`, `status`, `version` |
| `user_packs` | `id`, `user_id`, `pack_id`, `status`, `quota_per_period`, `available`, `total_credited`, `total_spent`, `activated_at`, `expires_at` |
| `yem_transactions` | `id`, `user_id`, `user_pack_id`, `reference`, `status`, `quantity_expected`, `quantity_received`, `direction`, `label`, `created_at` |
| `payouts` | `id`, `yem_transaction_id`, `user_id`, `yem_quantity`, `countervalue_xof`, `method`, `status`, `settled_at`, `settled_by` |
| `merchant_payments` | `id`, `user_id`, `user_pack_id`, `merchant_pernum`, `quantity`, `status`, `confirmed_at` |
| `notifications` | `id`, `user_id`, `type`, `payload` (JSON), `read_at` |
| `settings` | `key` (unique), `value`, `updated_by`, `updated_at` |
| `audit_logs` | `id`, `actor_id`, `action`, `target_type`, `target_id`, `payload` (JSON), `ip`, `created_at` |
| `otp_challenges` | `id`, `user_id`, `code_hash`, `expires_at`, `attempts`, `verified_at` |
| `user_sessions` | `id`, `user_id`, `token_hash`, `ip`, `user_agent`, `last_activity`, `revoked_at` |
| `exports` | `id`, `requested_by`, `type`, `format`, `date_from`, `date_to`, `status`, `file_path`, `generated_at` |

---

*Document généré le 2026-10-09 — à transmettre à l'équipe backend, DevOps et QA.*
