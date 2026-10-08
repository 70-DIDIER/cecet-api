# API CECT / YEM — Guide d'intégration frontend

Ce guide s'adresse à l'équipe qui développe le site et les espaces membre, marchand et back-office.
Il complète le contrat technique **`openapi.json`** (OpenAPI 3, toutes les routes, tous les champs) : le contrat dit *quoi* envoyer, ce guide dit *dans quel ordre* et *pourquoi*.

- Base : `https://<hôte>/api/v1` — documentation interactive en développement : `/docs/api` (Scramble).
- Version du contrat : à régénérer avec `php artisan scramble:export --path=docs/openapi.json` après toute évolution de l'API.

## 1. Conventions générales

| Sujet | Règle |
|---|---|
| Format | JSON uniquement (`Accept: application/json`, `Content-Type: application/json`). Les seuls envois de fichiers sont les imports CSV (`multipart/form-data`). |
| Enveloppe | Succès : `{ "data": … }`. Les listes paginées ajoutent `links` et `meta`. |
| Dates | ISO 8601 UTC, ex. `2026-10-08T13:14:00Z`. À convertir en heure locale (Lomé = UTC) à l'affichage. |
| Montants en FCFA | **Entiers** (`25000`), jamais de décimales. Champ `*_xof` ou `amount`. |
| Quantités YEM | **Chaînes de caractères** à 6 décimales (`"38.461538"`) : ne jamais les convertir en nombre flottant pour calculer. Les afficher arrondies si besoin, mais envoyer au serveur ce que l'utilisateur a saisi. |
| Taux de remise | En points de base (`discount_bps: 1500` = 15 %). |
| Identifiants | ULID (26 caractères), ex. `01m4dr2jd9e31mvqp06sbn3k2t`. |
| Langue des messages | Français. Le champ `code` des erreurs est stable : c'est lui qu'il faut tester, jamais `message`. |
| Corrélation | Chaque réponse porte l'en-tête `X-Correlation-Id`. **L'afficher dans les écrans d'erreur** : le support retrouve ainsi la requête exacte. |

### Pagination

- Listes classiques : `?page=2&per_page=25` → `{ data, links, meta: { current_page, last_page, per_page, total } }`.
- Journaux volumineux (quota, audit, tentatives de paiement) : pagination par curseur → `{ data, next_cursor, … }` ; renvoyer `?cursor=<next_cursor>` pour la page suivante, `next_cursor` vaut `null` à la dernière page. (Ici `next_cursor` est à la racine, pas dans `meta`.)

### Limitation de débit

Les routes répondent `429` (`TOO_MANY_REQUESTS`) avec l'en-tête `Retry-After` (secondes) au-delà du débit autorisé. Les codes OTP sont limités (renvois espacés) : désactiver le bouton « renvoyer » pendant le délai.

### Idempotence (`Idempotency-Key`)

Les opérations qui créent de l'argent, des réservations ou des décisions exigent l'en-tête **`Idempotency-Key`** (8 à 255 caractères, un UUID convient). Dans l'index des routes (§8), elles sont marquées « Idempotency-Key ».

- Générer **une clé par action de l'utilisateur** (au clic), la conserver tant que la requête n'a pas abouti, et la **renvoyer à l'identique en cas de nouvel essai** (coupure réseau, double clic).
- Rejouer la même clé avec le même contenu renvoie la **réponse d'origine** (en-tête `Idempotency-Replayed: true`) sans refaire l'opération.
- Même clé avec un contenu différent → `IDEMPOTENCY_KEY_MISMATCH` ; même clé pendant que la première requête tourne encore → `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` (attendre quelques secondes puis réessayer) ; en-tête absent → `400 IDEMPOTENCY_KEY_REQUIRED`.
- Les réponses en erreur (4xx/5xx) **libèrent** la clé : après correction du formulaire, on peut réutiliser la même.
- Nouvelle action de l'utilisateur = nouvelle clé.

## 2. Authentification

L'API accepte **deux modes**, choisis automatiquement :

| Mode | Pour qui | Fonctionnement |
|---|---|---|
| **Session (cookies)** | le site React hébergé sur un domaine déclaré (`SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`) | cookie de session `HttpOnly` ; **aucun jeton en `localStorage`** |
| **Jeton Bearer** | applications mobiles, scripts, tests | `Authorization: Bearer <access_token>` ; le jeton n'est renvoyé qu'**une seule fois** |

La réponse d'authentification indique le mode dans `data.mode` (`"session"` ou `"token"`).

### Mode session (site React)

1. Avant la première requête d'écriture : `GET /sanctum/csrf-cookie` (hors `/api/v1`). Le serveur pose le cookie `XSRF-TOKEN`.
2. Toutes les requêtes : `credentials: "include"` (fetch) / `withCredentials: true` (axios) et en-tête `X-XSRF-TOKEN` recopié depuis le cookie (axios le fait seul).
3. Le domaine de l'API et celui du site doivent être « same-site » (ex. `app.cect.tg` et `api.cect.tg`) ; en développement, `localhost:5173` est déjà autorisé.
4. Réponse `401 UNAUTHENTICATED` = session expirée : renvoyer vers la connexion et conserver la page visée.
5. Réponse `419` (`code` = `HTTP_419`) = jeton CSRF périmé : rappeler `/sanctum/csrf-cookie` et rejouer une fois.

### Connexion

`POST /auth/login` avec `identifier` (**Pernum ou e-mail**), `password`, et optionnellement `device_name`.

- `200` → `data.status = "authenticated"`, `data.user` (profil, rôles pour le personnel).
- `202` → `data.status = "otp_required"` : un code à 6 chiffres vient d'être **envoyé par e-mail** (comptes internes, et membres si la coopérative l'a activé). Afficher la saisie du code, puis `POST /auth/login/verify` (alias `POST /auth/mfa/verify`) avec `challenge_id` et `code`. `data.sent_to` donne l'adresse masquée à afficher (« c***@gmail.com »), `data.expires_at` l'échéance.
- Codes OTP : usage unique, durée courte, tentatives limitées. Prévoir `POST /auth/otp/resend` (`challenge_id`).
- Erreurs fréquentes : `INVALID_CREDENTIALS` (message volontairement identique que le compte existe ou non), `TOO_MANY_LOGIN_ATTEMPTS`, `ACCOUNT_DISABLED`, `ACCOUNT_NOT_ACTIVATED`, `OTP_INVALID` (code faux **ou expiré**, même message), `OTP_LOCKED` (trop d'essais), `OTP_RESEND_TOO_SOON` / `OTP_SEND_LIMIT_REACHED` (renvoi trop rapproché ou trop fréquent) — voir §7.

`POST /auth/logout` ferme la session / révoque le jeton. `GET /me` renvoie le profil courant (à appeler au chargement de l'application pour savoir si l'utilisateur est connecté et quel menu afficher). `GET /me/sessions` liste les appareils connectés ; `DELETE /me/sessions/{id}` ou `POST /me/sessions/revoke-others` les déconnecte.

### Mot de passe oublié

`POST /auth/password/forgot` (`email`, option `pernum`) répond toujours `202`, que le compte existe ou non. Puis `POST /auth/password/reset` (`challenge_id`, `code`, `password`, `password_confirmation`). Toutes les sessions du compte sont révoquées.

### Comptes internes (staff)

Créés par le Superadmin ; la personne reçoit un code par e-mail et l'active via `POST /auth/staff/activate` en choisissant son mot de passe.

### Ré-authentification pour les actions sensibles (« step-up »)

Les routes marquées **step-up** (validation d'un paiement, exports, modification de la destination de versement, imports…) exigent une confirmation récente par code e-mail :

1. L'appel renvoie `403` avec `code = "STEP_UP_REQUIRED"`.
2. `POST /auth/step-up` → `201` avec `challenge_id` (un code est envoyé par e-mail).
3. `POST /auth/step-up/verify` (`challenge_id`, `code`) → `{ "data": { "step_up_until": "…" } }` : la session peut agir jusqu'à cette heure (10 minutes par défaut).
4. Rejouer l'action (avec **la même `Idempotency-Key`** si l'appel en portait une).

Implémentation conseillée : un intercepteur qui détecte `STEP_UP_REQUIRED`, ouvre une fenêtre « Saisissez le code reçu par e-mail », puis rejoue la requête d'origine.

## 3. Parcours membre

### 3.1 Inscription et adhésion

1. **Choix du profil** : `person_type` = `natural` (personne physique) ou `legal` (personne morale : `legal_name`, `legal_form`, `rccm`, `nif` selon la configuration). La liste des statuts juridiques est configurable ; le montant de l'adhésion aussi (il est renvoyé à l'étape 2).
2. `POST /auth/register` *(Idempotency-Key)* — champs : `person_type`, `first_name`, `last_name`, `email`, `phone` (**format international `+22890123456`**, pas de règle « 10 chiffres »), `pernum`, `password` + `password_confirmation`, `city`, `preferred_payment_method`, `commitment_accepted` (case obligatoire), `signed_place`, `signed_on`. Réponse `201` : `data.user`, `data.verification` (challenge e-mail) et `data.membership_fee { amount, currency, payment_quote { net_amount, fee_amount, total_amount } }` → **afficher le total à payer et la ligne « frais de transaction » à la charge du membre**.
3. `POST /auth/email/verify` (`challenge_id`, `code`) → une **session est ouverte** et le compte passe en `pending_payment`.
4. **Paiement de l'adhésion** : voir §3.2. Tant que l'adhésion n'est pas payée et validée, le compte ne peut ni souscrire de pack ni faire d'opération.
5. `GET /me/membership` : statut (`pending_payment` → `active`), montant dû, devis, dernières tentatives de paiement. **Interroger toutes les 3 à 5 secondes** après un paiement par prestataire, jusqu'à `active`.

États du compte (`status`) : `pending_email_verification`, `pending_payment`, `pending_activation` (personnel), `active`, `suspended`, `closed`.

### 3.2 Payer (adhésion, pack, rechargement) — deux modes

Le serveur choisit le mode selon le moyen de paiement (configurable par la coopérative). L'objet `payment` renvoyé contient **`mode`** :

| `mode` | Moyens (par défaut) | Ce que le frontend fait |
|---|---|---|
| `psp` | T-Money, Flooz | rediriger vers `payment.checkout_url` (page hébergée par le prestataire), puis au retour interroger le statut (§3.1.5) |
| `manual` | Espèces, virement | afficher `payment.instructions` (texte avec montant et **référence `PAY-XXXXXXXX`** à citer), proposer « J'ai payé » → `POST /me/payments/{id}/declare` (`reference` libre : n° de dépôt, date…). Un **Validateur** de la CECT valide ensuite à la main. |

- Le membre peut **changer de moyen** : refaire l'appel de paiement avec un autre `method`, l'ancienne tentative est abandonnée.
- Le montant affiché vient toujours du serveur : `payment.amount` (total) = `net_amount` + `fee_amount` (frais de transaction du prestataire, **à la charge du membre** ; nuls pour les paiements manuels).
- Statuts d'une tentative : `created`, `pending`, `approved`, `declined`, `canceled`, `expired`, `failed`, `mismatch` (écart de montant, traité par la CECT). Un paiement manuel reste `pending` jusqu'à la décision du Validateur ; `declined` + motif s'il est refusé (un e-mail est envoyé au membre).
- Ne jamais considérer un paiement comme réussi à la seule vue du retour du prestataire : **seul le statut renvoyé par l'API fait foi** (confirmé côté serveur par le webhook signé).

Appels : adhésion `POST /me/membership/payments` (`method`, *Idempotency-Key*) — l'objet `payment` est directement dans `data` ; pack `POST /me/packs` (`plan_id`, `method`) ; rechargement `POST /me/packs/{id}/topups` (`amount_xof`, `method`).

### 3.3 Catalogue et souscription de packs

- `GET /packs` est **public** (visiteurs inclus). Connecté, chaque pack ajoute `eligible` (selon personne physique/morale) et `payment_quote` (prix + frais). `GET /packs/{id}` : détail.
- Familles : `VENDEUR` (vendre des YEM à la CECT), `CONSOMMATEUR` (quota mensuel pour payer des marchands), `PERSONNEL` (rechargeable à la demande), `MARCHAND` (réservé aux personnes morales : encaisser des YEM et les faire racheter).
- Un pack publié a une `current_version` : prix (`price_xof`), `period` (`unit`, `every`, `cycles`), `quota_per_period`, `total_quota`, `cumulative`, `end_of_term`, `eligible_person_types`, `rules` (ex. `discount_bps`, `reference_value_xof`, `min_topup_xof`). **Tout est configurable** : ne rien coder en dur (prix, nombre de niveaux, remises…), afficher ce que renvoie l'API.
- Limite de packs par compte (2 « structurés » par défaut) : erreur `PACK_LIMIT_REACHED`.
- `POST /me/packs` *(Idempotency-Key)* → `201` avec `user_pack` (statut `pending_payment`) et `payment`. Le pack passe `active` après paiement confirmé/validé. Un pack gratuit est activé immédiatement (`payment` vaut `null`).
- `GET /me/packs`, `GET /me/packs/{id}` (avec `periods`), `GET /me/quota-ledger` (mouvements, pagination par curseur, filtre `user_pack_id`).
- **Rechargement Perso** : le membre saisit le montant *qu'il paie* (≥ `rules.min_topup_xof` du pack) ; la réponse donne `topup.quantity` (YEM crédités), `discount_bps` et le cours utilisé, figés à la demande. Un seul rechargement en attente par pack : redemander le même montant le reprend.

Vocabulaire : le quota/solde est un **droit suivi par la plateforme**, pas une monnaie. Éviter les libellés du type « YEM virtuels ».

### 3.4 Tableau de bord membre

`GET /me/dashboard` fournit tout l'écran d'accueil en un appel : profil, adhésion, solde YEM (`balance`, `reserved`, `total_credited`, `total_spent`), packs actifs, 10 derniers mouvements avec libellé lisible (`label`, `direction` = `credit`/`debit`), et `payments_awaiting_action` (paiements manuels en attente de décision).

### 3.5 Vendre des YEM à la CECT (Pack Vendeur)

1. Enregistrer la destination de versement (une seule) : `GET/PUT /me/payout-destination` (*step-up* en écriture) — méthode (T-Money, Flooz, virement, espèces) et numéro.
2. `POST /me/exchange-requests` (`user_pack_id`, `quantity`) *(Idempotency-Key)* : la quantité est **réservée** et la réponse contient `transfer_instructions` (`send_to_pernum` = Pernum de la CECT, `from_pernum`, `quantity`, `reference` `EX-…`, `qr_payload`).
3. Le membre envoie réellement les YEM depuis son portefeuille YEM, puis `POST /me/exchange-requests/{id}/declare-sent` (`transfer_reference`). **Déclarer n'est pas une preuve** : rien n'est dû tant qu'un Validateur n'a pas confirmé.
4. Statuts : `awaiting_transfer` → `declared_sent` → `yem_received` → `completed` ; ou `canceled`, `expired` (réservation libérée automatiquement au bout de 24 h par défaut), `rejected`, `disputed`. Annulation possible avant déclaration : `POST …/cancel`.
5. Après `yem_received`, `payout` indique l'avancement du versement en FCFA (`status`, `total_amount`, `paid_amount`). Un virement en plusieurs parts est normal pour les gros montants.

## 4. Payer un marchand (Consommateur / Personnel)

1. Le membre scanne le QR du marchand (caméra mobile) **ou saisit le code marchand à la main** (obligatoire en secours). Le QR encode une URL `<APP_URL>/pay/m/<code>` : extraire `<code>` (dernier segment). L'hôte vient de la variable `APP_URL` du serveur : si le site doit ouvrir lui-même cette adresse, il lui faut une page `/pay/m/:code`, et `APP_URL` doit en tenir compte (à décider avec le backend).
2. `GET /merchant-payments/qr/{code}` → nom du marchand, point de vente, Pernum à créditer. **Afficher ces données serveur, jamais celles lues dans le QR.** `404 MERCHANT_QR_INVALID` si le QR est révoqué ; `409 MERCHANT_UNAVAILABLE` si le marchand n'accepte plus de paiement.
3. `POST /merchant-payments/intents` (`token`, `user_pack_id`, `quantity`) *(Idempotency-Key)* → paiement `reserved` (référence `MP-…`). **Le scan ne déplace aucun YEM.**
4. Le membre transfère les YEM au Pernum du marchand hors plateforme, puis `POST /me/merchant-payments/{id}/declare-sent`. Annulation possible avant déclaration (`…/cancel`) ; sans déclaration, la réservation expire (60 min par défaut).
5. Confirmation (`confirmation_mode`) : par le **marchand** (défaut) ou par un **Validateur**. Le payeur voit `confirmed`, `rejected`, `disputed` ou `expired` via `GET /me/merchant-payments/{id}` ; un e-mail l'informe.
6. Annuaire public : `GET /merchants?search=&city=` (marchands inscrits volontairement).

## 5. Espace marchand (Pack Marchand actif)

| Besoin | Appel |
|---|---|
| Créer / lister les QR | `POST /merchant/qr-codes` (`user_pack_id`, `outlet_label`), `GET /merchant/qr-codes` — `code` et `payload` ne sont donnés que pour un QR actif |
| Renouveler / révoquer un QR | `POST /merchant/qr-codes/{id}/rotate` (*step-up*, `reason`), `…/revoke` |
| Paiements reçus | `GET /merchant/payments?status=` |
| Confirmer la réception | `POST /merchant/payments/{id}/confirm` *(Idempotency-Key)* |
| Signaler « non reçu » | `POST /merchant/payments/{id}/report-not-received` (`reason`) → litige tranché par un Validateur |
| Solde | `GET /merchant/balance` : `eligible` (rééchangeable), `reserved`, `redeemed` + mouvements |
| Rééchange vers la CECT | `POST /merchant/redemptions` (`user_pack_id`, `quantity`) *(Idempotency-Key)* ; la suite (déclaration, annulation, suivi) utilise **les mêmes routes que la vente Vendeur** (`/me/exchange-requests/{id}/…`), l'objet porte `kind = "merchant_redemption"` et la référence `RD-…` |
| Fiche annuaire | `GET/PUT /merchant/directory-profile` (`listed` = apparaître ou non) |

## 6. Back-office (personnel)

Le menu dépend des **permissions** : `GET /me` renvoie `data.roles` et `data.permissions` (le Superadmin reçoit `["*"]`). Masquer ce qui n'est pas autorisé, mais ne jamais s'y fier : l'API répond `403 FORBIDDEN` de toute façon. Rôles : `superadmin`, `administrateur`, `validateur`, `comptabilite`.

- **Validations** : `GET /validations/queue?status=pending|history` — file unique (adhésions, packs, rechargements payés hors prestataire, YEM déclarés envoyés, paiements marchands à arbitrer). Chaque ligne : `kind`, `type`, `label`, `member`, `amount_xof` ou `quantity`, `method`, `declared_reference`, `status`. Actions : paiements `POST /validations/payments/{id}/approve` (`received_amount` **égal au montant dû**, `reference`) ou `…/reject` (`reason`) ; YEM `POST /validations/{id}/confirm` (`quantity_received`, `external_reference`), `…/reject`, `…/dispute` ; paiements marchands `POST /validations/merchant-payments/{id}/confirm|reject`. Toutes en *step-up*.
- **Comptabilité** : `GET /finance/payouts` (versements dus), détail, relance ciblée (`retry`), règlements manuels à deux personnes (`manual-settlements` : celui qui enregistre ne peut pas approuver).
- **Membres** : `GET /admin/members?search=&status=&family=` — la recherche accepte nom, e-mail, numéro de membre, **Pernum** et raison sociale ; chaque ligne porte `pernum`, `membership_status`, `active_families`, `yem_balance`.
- **Tableaux de bord** : `GET /admin/overview` (accueil), `GET /admin/reports/summary?month=YYYY-MM` ou `?from=&to=` (KPI, répartition par source et moyen de paiement, top membres, activité YEM, versements), version imprimable `GET /admin/reports/summary/print` (ouvrir dans un nouvel onglet puis « Imprimer → Enregistrer en PDF »).
- **Exports CSV** : `GET /admin/exports/{members|payments|subscriptions|merchant_payments}` (*step-up*, fichier téléchargé : séparateur `;`, UTF-8 avec BOM, ouvre directement dans Excel). Snapshots consolidés : `GET/POST /admin/snapshots`, téléchargement `GET /admin/snapshots/{id|latest}/download` (en-tête `X-Content-SHA256` pour contrôler le fichier).
- **Paramétrage** (Admin/Superadmin) : `GET /admin/settings`, `PUT /admin/settings/{clé}` (*step-up* ; corps `value`, `reason` obligatoire de 5 caractères minimum, `effective_from` optionnel pour programmer l'entrée en vigueur) — taux, frais, moyens de paiement, durées, seuils… Chaque réglage a un type, une description et des règles de validation ; construire le formulaire à partir de la réponse. Packs : `/admin/packs/plans` (brouillon → version → publication → suspension/retrait ; une version publiée est **immuable**), journal d'audit `GET /admin/audit-events`, rôles et permissions `/admin/roles`.
- **Santé** (Superadmin) : `GET /admin/ops/health`.

## 7. Gestion des erreurs

Format unique, **à plat**, pour toutes les erreurs :

```json
{
  "code": "VALIDATION_FAILED",
  "message": "Les données fournies sont invalides.",
  "details": { "fields": { "phone": ["Le champ téléphone n'est pas valide."] } },
  "correlation_id": "01m4dr2jd9e31mvqp06sbn3k2t"
}
```

- `422 VALIDATION_FAILED` : `details.fields.<champ>` → afficher chaque message sous le champ concerné (noms de champs = ceux envoyés, notation pointée pour les objets).
- `401 UNAUTHENTICATED` : renvoyer vers la connexion. `403 FORBIDDEN` : message « action non autorisée ». `403 STEP_UP_REQUIRED` : voir §2. `404`, `409`, `429` : messages génériques ou `message` renvoyé.
- Les erreurs métier (`details` libres) donnent des valeurs exploitables : ex. `PACK_NOT_ELIGIBLE` → `details.eligible_person_types`, `TOPUP_BELOW_MINIMUM` → `details.min_topup_xof`, `INSUFFICIENT_QUOTA` → `details.available`.
- `500 INTERNAL_ERROR` : afficher « Une erreur est survenue » + `correlation_id`. Ne jamais afficher de trace technique.
- **Ne jamais parser `message`** pour décider d'un comportement ; ne tester que `code`.
- Réseau : sur coupure pendant une opération financière, **rejouer avec la même `Idempotency-Key`** plutôt que de relancer une nouvelle action.

### Catalogue des codes d'erreur métier

| Code | HTTP | Message |
|---|---|---|
| `ACCOUNT_DISABLED` | 403 | Ce compte est suspendu ou fermé. |
| `ACCOUNT_NOT_ACTIVATED` | 403 | Ce compte n'a pas encore été activé. |
| `CECT_PERNUM_NOT_CONFIGURED` | 503 | Le Pernum de réception de la CECT n'est pas configuré : les ventes sont momentanément indisponibles. |
| `DEMO_MODE_UNAVAILABLE` | 404 | Le mode démonstration n'est pas disponible. |
| `EMAIL_NOT_VERIFIED` | 403 | Votre adresse email doit être vérifiée. Un nouveau code vous a été envoyé. |
| `EXCHANGE_NOT_CANCELABLE` | 409 | Une demande ne peut être annulée qu'avant la déclaration du transfert. |
| `EXCHANGE_NOT_OPEN` | 409 | Cette demande n'est plus en attente de décision. |
| `EXTERNAL_REFERENCE_REQUIRED` | 422 | La référence de la transaction YEM est obligatoire pour confirmer une réception. |
| `IMPORT_ALREADY_APPLIED` | 409 | Ce fichier a déjà été importé. |
| `IMPORT_BASE_SNAPSHOT_UNAVAILABLE` | 422 | Le snapshot de référence est introuvable, supprimé ou altéré : exportez un fichier plus récent. |
| `IMPORT_INVALID_ENCODING` | 422 | Le fichier doit être un CSV encodé en UTF-8. |
| `IMPORT_NOT_APPLICABLE` | 422 | Cet import contient des erreurs ou des conflits, ou aucun changement : corrigez le fichier et chargez-le à nouveau. |
| `IMPORT_NOT_STAGED` | 409 | Cet import n'est plus en attente de décision. |
| `IMPORT_SAME_APPROVER` | 403 | Un import doit être approuvé par une autre personne que celle qui l'a chargé. |
| `IMPORT_SCHEMA_MISMATCH` | 422 | Les colonnes du fichier ne correspondent pas à celles du snapshot de référence. |
| `IMPORT_STALE_DATA` | 409 | Des données ont changé sur la plateforme depuis le chargement : rien n'a été appliqué, chargez le fichier à nouveau. |
| `IMPORT_TOO_MANY_ROWS` | 422 | Le fichier dépasse {$max} lignes. |
| `INSUFFICIENT_MERCHANT_BALANCE` | 422 | Solde marchand insuffisant pour cette opération. |
| `INSUFFICIENT_QUOTA` | 422 | Quota disponible insuffisant. |
| `INSUFFICIENT_RESERVED_QUOTA` | 422 | La quantité réservée est insuffisante pour cette opération. |
| `INVALID_CREDENTIALS` | 401 | Identifiants invalides. |
| `INVALID_QUANTITY` | 422 | La quantité doit être un nombre strictement positif (6 décimales au plus). |
| `INVALID_STATUS_TRANSITION` | 409 | Transition de statut impossible : {$from} vers {$to}. |
| `LAST_SUPERADMIN` | 409 | Le dernier Superadmin actif ne peut pas être retiré ou suspendu. |
| `LEDGER_IDEMPOTENCY_CONFLICT` | 409 | Cette clé d'idempotence a déjà été utilisée pour une opération différente. |
| `MAKER_CHECKER_VIOLATION` | 403 | Le règlement doit être approuvé par une autre personne que celle qui l'a saisi. |
| `MEMBERSHIP_ALREADY_ACTIVE` | 409 | Votre adhésion est déjà active. |
| `MEMBERSHIP_NOT_PAYABLE` | 403 | Le paiement de l'adhésion n'est pas possible pour ce compte. |
| `MEMBERSHIP_REQUIRED` | 403 | Votre adhésion doit être active pour souscrire un pack. |
| `MERCHANT_PACK_REQUIRED` | 403 | Un pack Marchand actif est nécessaire pour encaisser des YEM. |
| `MERCHANT_PAYMENT_NOT_CANCELABLE` | 409 | Un paiement ne peut être annulé qu'avant la déclaration du transfert. |
| `MERCHANT_PAYMENT_NOT_OPEN` | 409 | Ce paiement n'est plus en attente. |
| `MERCHANT_PAYMENT_VALIDATOR_CONFIRMATION` | 403 | La confirmation de ce paiement est réservée aux Validateurs de la CECT. |
| `MERCHANT_PERNUM_REQUIRED` | 422 | Déclarez d'abord le Pernum Business sur lequel vous recevez les YEM. |
| `MERCHANT_QR_INVALID` | 404 | Ce QR code n'est pas reconnu ou n'est plus actif. |
| `MERCHANT_SELF_PAYMENT` | 422 | Vous ne pouvez pas vous payer vous-même. |
| `MERCHANT_UNAVAILABLE` | 409 | Ce marchand n'accepte pas de paiement pour le moment. |
| `OPERATION_OUT_OF_LIMITS` | 422 | La quantité est hors des limites autorisées par ce pack. |
| `OTP_INVALID` | 422 | Code invalide ou expiré. |
| `OTP_LOCKED` | 422 | Trop de tentatives sur ce code. Demandez un nouveau code. |
| `OTP_RESEND_TOO_SOON` | 429 | Veuillez patienter avant de demander un nouveau code. |
| `OTP_SEND_LIMIT_REACHED` | 429 | Nombre maximal de codes envoyés atteint. Réessayez plus tard. |
| `PACK_ARCHIVED` | 409 | Un pack archivé ne peut plus être modifié. |
| `PACK_CODE_TAKEN` | 409 | Un pack de code {$code} existe déjà. |
| `PACK_HAS_NO_PUBLISHED_VERSION` | 422 | Un pack ne peut être publié que s'il possède au moins une version publiée. |
| `PACK_LIMIT_REACHED` | 409 | Vous avez atteint le nombre maximal de packs structurés ({$max}). |
| `PACK_NOT_AVAILABLE` | 409 | Ce pack n'est pas disponible à la souscription. |
| `PACK_NOT_ELIGIBLE` | 422 | Ce pack n'est pas ouvert à votre type de compte. |
| `PACK_NOT_ELIGIBLE_FOR_EXCHANGE` | 422 | Seul un pack Vendeur permet de vendre des YEM à la CECT. |
| `PACK_NOT_ELIGIBLE_FOR_MERCHANT_PAYMENT` | 422 | Ce pack ne permet pas de payer un marchand. |
| `PACK_NOT_USABLE` | 409 | Ce pack n'est pas actif : le quota ne peut pas être réservé. |
| `PACK_TRANSITION_INVALID` | 409 | Transition de statut impossible : {$from} vers {$to}. |
| `PACK_VERSION_MISMATCH` | 404 | Cette version n'appartient pas à ce pack. |
| `PACK_VERSION_PUBLISHED` | 409 | Cette version est publiée : elle est figée. Créez une nouvelle version. |
| `PAYMENT_AMOUNT_MISMATCH` | 422 | Le montant reçu ne correspond pas au montant dû. |
| `PAYMENT_ATTEMPT_IN_PROGRESS` | 409 | Une tentative de paiement est déjà en cours de création. |
| `PAYMENT_FEES_NOT_CONFIGURED` | 503 | Les frais de transaction du prestataire de paiement ne sont pas configurés. |
| `PAYMENT_METHOD_NOT_AVAILABLE` | 422 | Ce moyen de paiement n'est pas disponible. |
| `PAYMENT_NOT_AWAITING_VALIDATION` | 409 | Ce paiement n'attend plus de validation. |
| `PAYMENT_NOT_MANUAL` | 409 | Ce paiement n'est pas un paiement à valider manuellement. |
| `PAYMENT_PROVIDER_UNAVAILABLE` | 503 | Le prestataire de paiement est indisponible. Réessayez dans quelques instants. |
| `PAYMENT_PROVIDER_UNKNOWN` | 404 | Prestataire de paiement inconnu. |
| `PAYOUT_ALREADY_PAID` | 409 | Ce payout est déjà entièrement réglé. |
| `PAYOUT_DESTINATION_REQUIRED` | 422 | Renseignez d'abord le moyen par lequel vous souhaitez recevoir vos paiements. |
| `PAYOUT_FEES_NOT_CONFIGURED` | 503 | Les frais de décaissement du prestataire ne sont pas configurés. |
| `PAYOUT_NOTHING_TO_RETRY` | 409 | Aucun envoi en échec à relancer pour ce payout. |
| `PAYOUT_NOT_DISPATCHABLE` | 409 | Ce payout ne peut pas être envoyé dans son état actuel. |
| `PAYOUT_PROVIDER_UNKNOWN` | 503 | Prestataire de décaissement inconnu. |
| `PAYOUT_TRANSFER_IN_PROGRESS` | 409 | Un envoi est en cours : attendez son résultat avant de régler autrement. |
| `PERMISSION_RESERVED` | 422 | Ces permissions sont réservées au Superadmin. |
| `PERMISSION_UNKNOWN` | 422 | Permission(s) inconnue(s). |
| `PERNUM_REQUIRED` | 422 | Aucun Pernum actif n'est associé à votre compte. |
| `QUOTA_ACCOUNT_MISSING` | 409 | Ce pack n'a pas de compte de quota. |
| `RECEIVED_EXCEEDS_REQUESTED` | 422 | La quantité reçue dépasse la quantité demandée : placez la demande en litige. |
| `ROLE_IN_USE` | 409 | Le rôle {$role} est encore attribué à des comptes. |
| `ROLE_NAME_TAKEN` | 409 | Un rôle nommé {$role} existe déjà. |
| `ROLE_PROTECTED` | 422 | Le rôle {$role} est protégé et ne peut pas être modifié ainsi. |
| `ROLE_UNKNOWN` | 422 | Rôle(s) inconnu(s). |
| `SEGREGATION_OF_DUTIES_VIOLATION` | 422 | Cette combinaison de permissions viole la séparation des tâches. |
| `SELF_MODIFICATION_FORBIDDEN` | 403 | Vous ne pouvez pas modifier vos propres droits ou statut. |
| `SETTING_NOT_CONFIGURED` | 503 | Le paramètre {$key} n'a aucune valeur effective. |
| `SETTING_NOT_FOUND` | 404 | Paramètre inconnu : {$key}. |
| `SETTING_RETROACTIVE_CHANGE` | 422 | Une nouvelle version de {$key} ne peut pas prendre effet dans le passé. |
| `SETTING_VALUE_FORBIDDEN_IN_PRODUCTION` | 422 | Cette valeur du paramètre {$key} est interdite en production. |
| `SETTING_VALUE_INVALID` | 422 | Valeur invalide pour le paramètre {$key}. |
| `SETTING_VERSION_CONFLICT` | 409 | Une version de {$key} existe déjà à cette date d'effet. |
| `SETTLEMENT_AMOUNT_MISMATCH` | 422 | Le règlement manuel doit couvrir exactement le reste dû. |
| `SETTLEMENT_NOT_PENDING` | 409 | Ce règlement n'attend pas d'approbation. |
| `SETTLEMENT_PENDING_APPROVAL` | 409 | Un règlement manuel attend déjà une approbation pour ce payout. |
| `STAFF_ONLY` | 403 | Espace réservé aux comptes internes. |
| `STEP_UP_REQUIRED` | 403 | Cette action exige une confirmation par code envoyé par email. |
| `SUPERADMIN_PROTECTED` | 403 | Seul un Superadmin peut gérer un compte ou le rôle Superadmin. |
| `TOO_MANY_LOGIN_ATTEMPTS` | 429 | Trop de tentatives de connexion. Réessayez plus tard. |
| `TOPUP_BELOW_MINIMUM` | 422 | Le rechargement minimum est de {$minimum} FCFA. |
| `TOPUP_IN_PROGRESS` | 409 | Un rechargement est déjà en cours de paiement ou de validation pour ce pack. |
| `TOPUP_NOT_AVAILABLE` | 409 | Seul un pack Personnel actif peut être rechargé. |
| `WEBHOOK_REJECTED` | 400 | Webhook rejeté. |
| `WRONG_ACCOUNT_KIND` | 422 | Cette opération ne s'applique pas à ce type de compte. |

## 8. Index des routes

Légende : *connecté* = authentification requise (cookie de session ou Bearer) · *staff* = compte interne uniquement · *step-up* = confirmation récente par code e-mail · *Idempotency-Key* = en-tête obligatoire · *permission x* = droit requis (le Superadmin les a toutes).

### Authentification et compte

| Méthode | Chemin | Accès |
|---|---|---|
| POST | `/auth/email/verify` | public |
| POST | `/auth/login` | public |
| POST | `/auth/login/verify` | public |
| POST | `/auth/logout` | connecté |
| POST | `/auth/mfa/verify` | public |
| POST | `/auth/otp/resend` | public |
| POST | `/auth/password/forgot` | public |
| POST | `/auth/password/reset` | public |
| POST | `/auth/register` | Idempotency-Key |
| POST | `/auth/staff/activate` | public |
| POST | `/auth/step-up` | connecté |
| POST | `/auth/step-up/verify` | connecté |
| GET | `/me` | connecté |
| GET | `/me/sessions` | connecté |
| POST | `/me/sessions/revoke-others` | connecté |
| DELETE | `/me/sessions/{session}` | connecté |

### Catalogue public

| Méthode | Chemin | Accès |
|---|---|---|
| GET | `/merchants` | public |
| GET | `/packs` | public |
| GET | `/packs/{plan}` | public |

### Adhésion, paiements, packs et quota (membre)

| Méthode | Chemin | Accès |
|---|---|---|
| GET | `/me/dashboard` | connecté |
| GET | `/me/membership` | connecté |
| POST | `/me/membership/demo-activation` | connecté |
| POST | `/me/membership/payments` | connecté · Idempotency-Key |
| GET | `/me/membership/payments/{attempt}` | connecté |
| POST | `/me/membership/payments/{attempt}/refresh` | connecté |
| GET | `/me/packs` | connecté |
| POST | `/me/packs` | connecté · Idempotency-Key |
| GET | `/me/packs/{userPack}` | connecté |
| GET | `/me/packs/{userPack}/topups` | connecté |
| POST | `/me/packs/{userPack}/topups` | connecté · Idempotency-Key |
| POST | `/me/payments/{attempt}/declare` | connecté |
| GET | `/me/quota-ledger` | connecté |

### Vente de YEM à la CECT (Vendeur)

| Méthode | Chemin | Accès |
|---|---|---|
| GET | `/me/exchange-requests` | connecté |
| POST | `/me/exchange-requests` | connecté · Idempotency-Key |
| GET | `/me/exchange-requests/{exchange}` | connecté |
| POST | `/me/exchange-requests/{exchange}/cancel` | connecté |
| POST | `/me/exchange-requests/{exchange}/declare-sent` | connecté |
| GET | `/me/payout-destination` | connecté |
| PUT | `/me/payout-destination` | connecté · step-up |

### Paiement et espace marchand

| Méthode | Chemin | Accès |
|---|---|---|
| GET | `/me/merchant-payments` | connecté |
| GET | `/me/merchant-payments/{payment}` | connecté |
| POST | `/me/merchant-payments/{payment}/cancel` | connecté |
| POST | `/me/merchant-payments/{payment}/declare-sent` | connecté |
| POST | `/merchant-payments/intents` | connecté · Idempotency-Key |
| GET | `/merchant-payments/qr/{token}` | connecté |
| GET | `/merchant/balance` | connecté |
| GET | `/merchant/directory-profile` | connecté |
| PUT | `/merchant/directory-profile` | connecté |
| GET | `/merchant/payments` | connecté |
| POST | `/merchant/payments/{payment}/confirm` | connecté · Idempotency-Key |
| POST | `/merchant/payments/{payment}/report-not-received` | connecté |
| GET | `/merchant/qr-codes` | connecté |
| POST | `/merchant/qr-codes` | connecté · Idempotency-Key |
| POST | `/merchant/qr-codes/{qr}/revoke` | connecté |
| POST | `/merchant/qr-codes/{qr}/rotate` | connecté · step-up |
| POST | `/merchant/redemptions` | connecté · Idempotency-Key |

### Validations (Validateur)

| Méthode | Chemin | Accès |
|---|---|---|
| GET | `/validations/merchant-payments/{payment}` | connecté · staff · permission yem_validations.view |
| POST | `/validations/merchant-payments/{payment}/confirm` | connecté · staff · step-up · Idempotency-Key · permission yem_validations.confirm |
| POST | `/validations/merchant-payments/{payment}/reject` | connecté · staff · step-up · permission yem_validations.confirm |
| POST | `/validations/payments/{attempt}/approve` | connecté · staff · step-up · Idempotency-Key · permission payments.validate_manual |
| POST | `/validations/payments/{attempt}/reject` | connecté · staff · step-up · permission payments.validate_manual |
| GET | `/validations/pending` | connecté · staff · permission yem_validations.view |
| GET | `/validations/queue` | connecté · staff |
| GET | `/validations/{exchange}` | connecté · staff · permission yem_validations.view |
| POST | `/validations/{exchange}/confirm` | connecté · staff · step-up · Idempotency-Key · permission yem_validations.confirm |
| POST | `/validations/{exchange}/dispute` | connecté · staff · step-up · permission yem_validations.dispute |
| POST | `/validations/{exchange}/reject` | connecté · staff · step-up · permission yem_validations.confirm |

### Finance (Comptabilité)

| Méthode | Chemin | Accès |
|---|---|---|
| GET | `/finance/manual-settlements` | connecté · staff · permission payouts.view |
| POST | `/finance/manual-settlements/{settlement}/approve` | connecté · staff · step-up · permission payouts.approve_manual |
| POST | `/finance/manual-settlements/{settlement}/reject` | connecté · staff · step-up · permission payouts.approve_manual |
| GET | `/finance/payouts` | connecté · staff · permission payouts.view |
| GET | `/finance/payouts/{payout}` | connecté · staff · permission payouts.view |
| POST | `/finance/payouts/{payout}/dispatch` | connecté · staff · step-up · permission payouts.retry |
| POST | `/finance/payouts/{payout}/manual-settlements` | connecté · staff · step-up · Idempotency-Key · permission payouts.settle_manual |
| POST | `/finance/payouts/{payout}/refresh` | connecté · staff · permission payouts.view |
| POST | `/finance/payouts/{payout}/retry` | connecté · staff · step-up · permission payouts.retry |

### Administration

| Méthode | Chemin | Accès |
|---|---|---|
| GET | `/admin/audit-events` | connecté · staff · permission audit.view |
| GET | `/admin/exports/{type}` | connecté · staff · step-up · permission reports.export |
| GET | `/admin/imports` | connecté · staff · permission imports.manage |
| POST | `/admin/imports` | connecté · staff · step-up · Idempotency-Key · permission imports.manage |
| GET | `/admin/imports/{import}` | connecté · staff · permission imports.manage |
| POST | `/admin/imports/{import}/approve` | connecté · staff · step-up · Idempotency-Key · permission imports.manage |
| POST | `/admin/imports/{import}/cancel` | connecté · staff · permission imports.manage |
| GET | `/admin/members` | connecté · staff · permission members.view |
| GET | `/admin/members/{user}` | connecté · staff |
| POST | `/admin/members/{user}/reactivate` | connecté · staff · step-up · permission members.manage |
| POST | `/admin/members/{user}/suspend` | connecté · staff · step-up · permission members.manage |
| GET | `/admin/merchant-accounts/{userPack}` | connecté · staff · permission user_packs.view |
| POST | `/admin/merchant-accounts/{userPack}/adjustments` | connecté · staff · step-up · Idempotency-Key · permission quota.adjust |
| GET | `/admin/merchant-payments` | connecté · staff · permission user_packs.view |
| GET | `/admin/ops/health` | connecté · staff · permission security_events.view |
| GET | `/admin/overview` | connecté · staff · permission reports.view |
| GET | `/admin/packs/plans` | connecté · staff · permission packs.view |
| POST | `/admin/packs/plans` | connecté · staff · step-up · Idempotency-Key · permission packs.manage |
| GET | `/admin/packs/plans/{plan}` | connecté · staff · permission packs.view |
| POST | `/admin/packs/plans/{plan}/duplicate` | connecté · staff · step-up · Idempotency-Key · permission packs.manage |
| POST | `/admin/packs/plans/{plan}/transition` | connecté · staff · step-up · permission packs.manage |
| POST | `/admin/packs/plans/{plan}/versions` | connecté · staff · step-up · Idempotency-Key · permission packs.manage |
| DELETE | `/admin/packs/plans/{plan}/versions/{version}` | connecté · staff · step-up · permission packs.manage |
| POST | `/admin/packs/plans/{plan}/versions/{version}/publish` | connecté · staff · step-up · permission packs.manage |
| GET | `/admin/payments/attempts` | connecté · staff · permission payments.view |
| GET | `/admin/payments/webhook-events` | connecté · staff · permission payments.view |
| GET | `/admin/permissions` | connecté · staff · permission roles.view |
| GET | `/admin/reports/summary` | connecté · staff · permission reports.view |
| GET | `/admin/reports/summary/print` | connecté · staff · permission reports.view |
| GET | `/admin/roles` | connecté · staff · permission roles.view |
| POST | `/admin/roles` | connecté · staff · step-up · Idempotency-Key · permission roles.manage |
| DELETE | `/admin/roles/{role}` | connecté · staff · step-up · permission roles.manage |
| PUT | `/admin/roles/{role}/permissions` | connecté · staff · step-up · permission roles.manage |
| GET | `/admin/security-events` | connecté · staff · permission security_events.view |
| GET | `/admin/settings` | connecté · staff · permission settings.view |
| GET | `/admin/settings/{key}` | connecté · staff · permission settings.view |
| PUT | `/admin/settings/{key}` | connecté · staff · step-up · Idempotency-Key |
| GET | `/admin/snapshots` | connecté · staff · permission reports.view |
| POST | `/admin/snapshots` | connecté · staff · permission reports.export |
| GET | `/admin/snapshots/{snapshot}/download` | connecté · staff · step-up · permission reports.export |
| GET | `/admin/staff` | connecté · staff · permission staff.view |
| POST | `/admin/staff` | connecté · staff · step-up · Idempotency-Key · permission staff.manage |
| GET | `/admin/staff/{user}` | connecté · staff |
| POST | `/admin/staff/{user}/reactivate` | connecté · staff · step-up · permission staff.manage |
| PUT | `/admin/staff/{user}/roles` | connecté · staff · step-up · permission staff.manage |
| POST | `/admin/staff/{user}/suspend` | connecté · staff · step-up · permission staff.manage |
| GET | `/admin/user-packs` | connecté · staff · permission user_packs.view |
| GET | `/admin/user-packs/{userPack}` | connecté · staff · permission user_packs.view |
| POST | `/admin/user-packs/{userPack}/adjustments` | connecté · staff · step-up · Idempotency-Key · permission quota.adjust |

### Webhooks et santé

| Méthode | Chemin | Accès |
|---|---|---|
| GET | `/health` | public |
| POST | `/webhooks/payments/{provider}` | public |

## 9. Environnement de développement et de recette

- Les e-mails (codes OTP, notifications) sont écrits dans `storage/logs/laravel.log` tant que `MAIL_MAILER=log` : y lire les codes à 6 chiffres.
- Prestataire de paiement factice (`payment.inbound_provider = fake`) : après avoir lancé un paiement, `php artisan payments:fake-settle <id_tentative> [approved|declined|canceled]` simule la réponse du prestataire et affiche le webhook signé à envoyer. Disponible hors production seulement.
- `POST /me/membership/demo-activation` active un compte sans paiement : **hors production uniquement** (`404` en production). Ne jamais l'exposer dans l'interface livrée.
- Cookies en local : site sur `http://localhost:5173`, API sur `http://localhost:8000` — déjà autorisés (CORS + Sanctum). Un autre port ou un autre domaine doit être ajouté dans `.env` (`SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`).

## 10. Points d'attention

- **Rien n'est en dur côté frontend** : prix, quotas, nombre de niveaux, moyens de paiement actifs, statuts juridiques, textes d'instructions de paiement viennent de l'API ou de la configuration. Les cours (achat, vente, cession) ne sont pas à afficher comme des constantes.
- Liste des moyens de paiement : il n'existe pas encore de route publique qui les énumère ; en attendant, le formulaire utilise `TMONEY`, `FLOOZ`, `CASH`, `BANK_TRANSFER` (codes acceptés par `method`). Une valeur refusée renvoie `PAYMENT_METHOD_NOT_AVAILABLE`.
- Les documents téléchargeables du tableau de bord membre (carte de membre, reçus, contrats) ne sont **pas encore fournis par l'API**.
- Les montants du site (adhésion personne morale 50 000 ou 200 000 F selon l'écran) doivent venir de `membership_fee` / `GET /me/membership`, pas des textes statiques.
- Mobile : prévoir pour chaque tableau (Validateur, Comptabilité) une présentation en cartes ; le parcours QR doit proposer la saisie manuelle du code marchand.
