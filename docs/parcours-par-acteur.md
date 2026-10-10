# Plateforme CECT / YEM — Parcours d'utilisation par acteur

Ce document décrit, pour chaque acteur de la plateforme, ce qu'il peut faire, dans quel ordre, ce qui lui est interdit et ce qu'il voit en retour. Il complète `docs/guide-integration-frontend.md` (conventions techniques, erreurs, index des routes) et `docs/openapi.json` (contrat complet).

Les chemins d'API sont relatifs à `/api/v1`. Les montants sont en FCFA (entiers), les quantités de YEM sont des décimales à 6 chiffres. Tout ce qui est chiffré (prix, taux, frais, durées, seuils, quotas) est **configurable** par la coopérative : les valeurs données ici sont des valeurs par défaut ou de travail, pas des constantes du code.

---

## 1. Vue d'ensemble

### 1.1 Principe de la plateforme

La plateforme **n'est pas le portefeuille YEM** : les transferts de YEM se font hors plateforme, dans l'écosystème YEM (identifié par le Pernum). La plateforme gère les adhésions, les packs, les quotas, les intentions d'opération, les validations, les contre-valeurs en FCFA et les preuves.

Règles qui valent pour tous les parcours :

- Cliquer sur « J'ai envoyé » ne prouve jamais un transfert. Seul un **Validateur** confirme l'arrivée des YEM sur le Pernum de la CECT.
- Tous les montants et quantités sont recalculés par le serveur à partir d'un paramétrage versionné. Aucune valeur passée n'est recalculée quand un taux ou un pack change.
- Les frais de transaction du prestataire de paiement sont **à la charge du membre** ; la coopérative perçoit le montant net.
- Chaque action sensible est tracée dans le journal d'audit (qui, quand, avant/après, résultat).
- Les actions financières sont idempotentes : rejouer la même demande ne crée pas de doublon.

### 1.2 Les acteurs

| Acteur | Nature | Rôle en une phrase |
|---|---|---|
| **Visiteur** | public | Consulte le catalogue des packs et l'annuaire des marchands, peut s'inscrire. |
| **Membre** (personne physique ou morale) | externe | Adhère, souscrit des packs, vend des YEM à la CECT, paie des marchands, suit ses opérations. |
| **Marchand** | membre personne morale avec un Pack Marchand | Encaisse des YEM auprès de clients et les fait racheter par la CECT. |
| **Validateur** | personnel interne | Vérifie les paiements hors prestataire et l'arrivée réelle des YEM sur le Pernum CECT. |
| **Comptabilité** | personnel interne | Suit et règle les contre-valeurs en FCFA dues aux membres et aux marchands. |
| **Administrateur** | personnel interne | Gère les membres, le catalogue de packs, le paramétrage courant, les rapports. |
| **Superadmin** | personnel interne | Gouvernance : comptes internes, rôles, prestataires, paramètres critiques, audit, sécurité. |
| **Prestataire de paiement (FedaPay)** | système externe | Encaisse les paiements entrants (T-Money, Flooz) et exécute les versements. |
| **YEM / YEMPay** | système externe | Exécute les transferts de YEM entre Pernum, hors plateforme. |
| **Tâches planifiées** | système interne | Crédit des périodes de quota, expirations, relais des e-mails, rapprochement, snapshots, supervision. |

> Personne physique et personne morale sont des **types de membre**, pas des rôles. Vendeur, Consommateur, Personnel et Marchand sont des **capacités qui découlent des packs détenus**, pas des rôles.

### 1.3 Séparation des tâches

| Règle | Application |
|---|---|
| Le Validateur confirme l'arrivée des YEM, mais ne fixe pas le montant en FCFA et ne marque jamais un versement comme payé. | `yem_validations.confirm` est incompatible avec `payouts.settle_manual`, `payouts.retry` et `payouts.approve_manual` : une même identité ne peut pas cumuler ces droits. |
| La Comptabilité voit la validation YEM mais ne peut pas la modifier. | La colonne « YEM confirmé » est une projection en lecture seule. |
| Un règlement manuel est approuvé par une autre personne que celle qui l'a saisi. | Maker-checker (`MAKER_CHECKER_VIOLATION`). |
| Un import de fichier est approuvé par une autre personne que celle qui l'a chargé. | `IMPORT_SAME_APPROVER`. |
| Un Administrateur ne peut ni se promouvoir Superadmin, ni modifier un Superadmin. | `SUPERADMIN_PROTECTED`, `SELF_MODIFICATION_FORBIDDEN`. |
| Le dernier Superadmin actif ne peut pas être retiré ou suspendu. | `LAST_SUPERADMIN`. |
| Un échec de versement n'annule jamais une validation YEM déjà acquise. | Le quota reste consommé. |

---

## 2. Visiteur (public)

Sans compte, il peut :

| Action | Appel |
|---|---|
| Consulter le catalogue des packs publiés | `GET /packs`, `GET /packs/{id}` |
| Consulter l'annuaire des marchands inscrits volontairement | `GET /merchants?search=&city=` |
| Vérifier que l'API est en ligne | `GET /health` |
| S'inscrire | `POST /auth/register` (voir §3.1) |
| Se connecter, réinitialiser son mot de passe | `POST /auth/login`, `POST /auth/password/forgot` |

Il ne voit ni son prix personnalisé ni son éligibilité : ces informations n'apparaissent qu'une fois connecté.

---

## 3. Membre

### 3.1 Inscription et adhésion

Le parcours est le même pour une personne physique et une personne morale, avec des champs et un montant d'adhésion différents.

| Étape | Ce que fait le membre | Appel | Résultat |
|---|---|---|---|
| 1 | Choisit son profil : personne physique (`natural`) ou personne morale (`legal`). | — | Les champs demandés dépendent du profil. |
| 2 | Remplit le formulaire : nom, prénoms, e-mail, téléphone (format international), Pernum, mot de passe, ville, moyen de paiement préféré, acceptation de l'engagement, lieu et date de signature. Une personne morale ajoute raison sociale, forme juridique, RCCM, NIF et son **Pernum Business**. | `POST /auth/register` | Compte créé. La réponse donne le montant d'adhésion (par défaut **25 000 F** pour une personne physique, **200 000 F** pour une personne morale) et le devis : net, frais de transaction, total. |
| 3 | Saisit le code à 6 chiffres reçu par e-mail. | `POST /auth/email/verify` | Une session est ouverte. Le statut devient `pending_payment`. |
| 4 | Paie l'adhésion. | `POST /me/membership/payments` | Voir §3.2. |
| 5 | Attend l'activation. | `GET /me/membership` (toutes les 3 à 5 secondes après un paiement par prestataire) | Le statut passe à `active` quand le paiement est confirmé. |

Tant que l'adhésion n'est pas active, le membre ne peut ni souscrire de pack, ni faire d'opération. Deux comptes ne peuvent pas partager le même e-mail ou le même Pernum.

**Statuts d'un compte** : `pending_email_verification` → `pending_payment` → `active`, puis éventuellement `suspended` ou `closed`.

### 3.2 Payer (adhésion, pack, rechargement)

Le serveur choisit le mode de paiement selon le moyen choisi.

| Mode | Moyens par défaut | Parcours |
|---|---|---|
| **Prestataire** (`psp`) | T-Money, Flooz | Le membre est redirigé vers la page de paiement hébergée par FedaPay (`checkout_url`). Au retour, la plateforme interroge le statut. Un **webhook signé** de FedaPay confirme le paiement côté serveur : le retour dans le navigateur ne suffit jamais à activer quoi que ce soit. |
| **Manuel** (`manual`) | Espèces, virement | La plateforme affiche les instructions et une **référence `PAY-XXXXXXXX`** à citer. Le membre paie, puis clique « J'ai payé » (`POST /me/payments/{id}/declare`, avec une référence libre : n° de dépôt, date…). Un **Validateur** valide ensuite à la main. |

À savoir :

- Le membre peut **changer de moyen de paiement** : l'ancienne tentative est abandonnée.
- Le montant affiché vient toujours du serveur : total = net + frais de transaction (les frais sont nuls pour les paiements manuels).
- Statuts d'une tentative : `created`, `pending`, `approved`, `declined`, `canceled`, `expired`, `failed`, `mismatch` (écart de montant, traité par la CECT).
- Si un paiement manuel est refusé, le membre reçoit un e-mail avec le motif.

### 3.3 Catalogue et souscription de packs

| Action | Appel |
|---|---|
| Voir le catalogue (avec éligibilité et prix à payer, une fois connecté) | `GET /packs` |
| Souscrire un pack | `POST /me/packs` (`plan_id`, `method`) |
| Lister ses packs et voir le détail avec les périodes | `GET /me/packs`, `GET /me/packs/{id}` |
| Voir l'historique de son quota | `GET /me/quota-ledger` |

Les quatre familles de packs :

| Famille | À quoi elle sert | Pour qui |
|---|---|---|
| **Vendeur** | Vendre des YEM à la CECT, dans une limite par période. | Personnes physiques et morales selon le pack. |
| **Consommateur** | Disposer d'un quota de YEM pour payer des marchands, avec une remise configurable (plage de travail 2 à 5 %, à ratifier). | Selon éligibilité du pack. |
| **Personnel** | Acheter des YEM au besoin et les consommer tant qu'il y a un solde. Pas de quota ni de durée fixes. | Tout membre actif. |
| **Marchand** | Accepter des YEM et demander leur rééchange à la CECT. | **Personnes morales uniquement.** |

Règles :

- Un compte peut détenir au maximum **2 packs structurés actifs** (paramètre configurable). Le Pack Personnel n'entre pas dans cette limite. Erreur : `PACK_LIMIT_REACHED`.
- Un pack acheté **conserve les conditions de sa version d'achat**, même si la coopérative publie ensuite une nouvelle version.
- Le pack passe de `pending_payment` à `active` après paiement confirmé ou validé. Un pack gratuit est activé immédiatement.
- Les packs à période (Vendeur, Consommateur, Marchand) créditent leur quota à chaque période, automatiquement. Le quota non utilisé se cumule ou non selon la configuration du pack.

### 3.4 Tableau de bord du membre

`GET /me/dashboard` fournit l'écran d'accueil en un appel :

- identité et statut de l'adhésion ;
- solde de YEM (disponible, réservé, total crédité, total dépensé) ;
- packs actifs ;
- les 10 derniers mouvements, avec un libellé lisible et le sens (crédit ou débit) ;
- les paiements manuels en attente de décision.

Le membre gère aussi son compte : `GET /me` (profil), `GET /me/sessions` (appareils connectés), `DELETE /me/sessions/{id}` ou `POST /me/sessions/revoke-others` (déconnexion d'un ou de tous les autres appareils), `POST /auth/logout`.

### 3.5 Pack Personnel : recharger son solde

| Étape | Détail |
|---|---|
| Saisir le montant à payer | Minimum fixé par le pack (`min_topup_xof`). |
| Demander le rechargement | `POST /me/packs/{id}/topups` → la réponse donne la quantité de YEM créditée, la remise et le cours utilisés, **figés à la demande**. |
| Payer | Comme au §3.2. |
| Suivre | `GET /me/packs/{id}/topups`. |

Un seul rechargement en attente par pack : redemander le même montant le reprend.

### 3.6 Vendre des YEM à la CECT (Pack Vendeur)

```
Destination de versement → Demande de vente → Transfert réel (hors plateforme)
→ Déclaration « envoyé » → Confirmation par un Validateur → Versement en FCFA
```

| Étape | Ce que fait le membre | Appel | Effet sur le quota |
|---|---|---|---|
| 1 | Enregistre **une** destination de versement (T-Money, Flooz, virement ou espèces, avec le numéro). Action sensible, confirmation par code e-mail (step-up). | `PUT /me/payout-destination` | — |
| 2 | Crée la demande avec la quantité à vendre (au plus son disponible). | `POST /me/exchange-requests` | La quantité est **réservée**. La réponse contient les instructions : Pernum de la CECT, quantité, référence `EX-…`, contenu du QR. |
| 3 | Envoie réellement les YEM depuis son portefeuille YEM vers le Pernum de la CECT. | (hors plateforme) | — |
| 4 | Déclare l'envoi avec la référence du transfert. | `POST /me/exchange-requests/{id}/declare-sent` | Aucun débit définitif : **déclarer n'est pas une preuve**. |
| 5 | Attend la décision du Validateur. | `GET /me/exchange-requests/{id}` | Si confirmé : la réservation devient une consommation. |
| 6 | Suit le versement en FCFA. | même appel, bloc `payout` | Le quota reste consommé même si le versement échoue. |

Statuts : `awaiting_transfer` → `declared_sent` → `yem_received` → `completed`. Autres issues : `canceled` (annulation avant déclaration, `POST …/cancel`, réservation libérée), `expired` (réservation libérée automatiquement, 24 h par défaut), `rejected`, `disputed`.

### 3.7 Payer un marchand (Consommateur ou Personnel)

```
Scan du QR (ou saisie du code) → Intention de paiement (réservation)
→ Transfert réel (hors plateforme) → Déclaration → Confirmation → Quota consommé
```

| Étape | Ce que fait le membre | Appel |
|---|---|---|
| 1 | Scanne le QR du marchand avec la caméra, **ou saisit le code marchand à la main** (secours obligatoire sans caméra). | — |
| 2 | Vérifie les informations affichées : nom du marchand, point de vente, Pernum à créditer. Ces données viennent **toujours du serveur**, jamais du contenu brut du QR. | `GET /merchant-payments/qr/{code}` |
| 3 | Choisit le pack et la quantité. | `POST /merchant-payments/intents` → paiement `reserved`, référence `MP-…` |
| 4 | Transfère les YEM au Pernum du marchand, hors plateforme, puis déclare. | `POST /me/merchant-payments/{id}/declare-sent` |
| 5 | Suit le résultat : `confirmed`, `rejected`, `disputed` ou `expired`. Un e-mail l'informe. | `GET /me/merchant-payments/{id}` |

- Scanner ne déplace **aucun** YEM.
- Annulation possible avant la déclaration (`…/cancel`). Sans déclaration, la réservation expire (60 minutes par défaut).
- La confirmation est faite par le **marchand** (cas général) ou par un **Validateur** selon le mode de confirmation.
- On ne peut pas se payer soi-même (`MERCHANT_SELF_PAYMENT`).

---

## 4. Marchand (membre personne morale avec un Pack Marchand actif)

Le marchand dispose, en plus de tout ce qu'un membre peut faire, d'un espace dédié sous `/merchant`.

### 4.1 Mise en route

| Action | Appel |
|---|---|
| Disposer d'un Pernum Business actif (saisi à l'inscription de la personne morale ; sans lui, l'encaissement est refusé : `MERCHANT_PERNUM_REQUIRED`) | `GET /me` |
| Créer un QR code, éventuellement par point de vente | `POST /merchant/qr-codes` |
| Lister ses QR | `GET /merchant/qr-codes` |
| Renouveler un QR (changement de Pernum, fuite…), avec motif ; action sensible (step-up) | `POST /merchant/qr-codes/{id}/rotate` |
| Révoquer un QR | `POST /merchant/qr-codes/{id}/revoke` |
| Apparaître ou non dans l'annuaire public | `GET/PUT /merchant/directory-profile` |

Un QR est signé, versionné et peut être révoqué. Il ne prouve jamais un paiement.

### 4.2 Encaisser

| Étape | Appel |
|---|---|
| Voir les paiements reçus, filtrés par statut | `GET /merchant/payments?status=` |
| Confirmer la réception réelle des YEM | `POST /merchant/payments/{id}/confirm` |
| Signaler « non reçu » (motif obligatoire) → le litige est tranché par un Validateur | `POST /merchant/payments/{id}/report-not-received` |
| Consulter son solde : `eligible` (rééchangeable), `reserved`, `redeemed`, et les mouvements | `GET /merchant/balance` |

### 4.3 Se faire racheter des YEM par la CECT (rééchange)

```
Demande de rééchange (réservation) → Transfert réel vers le Pernum CECT
→ Déclaration → Confirmation par un Validateur → Versement en FCFA
```

| Étape | Appel |
|---|---|
| Demander un rééchange (quantité au plus égale au solde éligible, dans les limites du pack) | `POST /merchant/redemptions` → référence `RD-…` |
| Déclarer l'envoi, annuler, suivre | les mêmes routes que la vente Vendeur : `/me/exchange-requests/{id}/…` (l'objet porte `kind = merchant_redemption`) |

Le marchand doit avoir enregistré sa destination de versement (`PAYOUT_DESTINATION_REQUIRED` sinon).

---

## 5. Validateur

**Permissions** : `yem_validations.view`, `yem_validations.confirm`, `yem_validations.dispute`, `payments.validate_manual`.

**Mission** : vérifier que ce qui est déclaré s'est réellement produit.

### 5.1 La file unique

`GET /validations/queue?status=pending|history` : une seule liste contenant :

- les paiements d'adhésion, de pack ou de rechargement déclarés hors prestataire ;
- les YEM déclarés envoyés vers le Pernum de la CECT ;
- les paiements marchands à arbitrer (litiges, ou confirmation réservée aux Validateurs).

Chaque ligne indique le type, le libellé, le membre, le montant ou la quantité, le moyen, la référence déclarée et le statut. L'historique montre les décisions récentes.

### 5.2 Décisions possibles

| Objet | Confirmer | Rejeter | Litige |
|---|---|---|---|
| **Paiement hors prestataire** | `POST /validations/payments/{id}/approve` : saisir le montant reçu, qui doit **égaler le montant dû**, et une référence. | `POST /validations/payments/{id}/reject` : motif obligatoire. Un e-mail part au membre. | — |
| **Réception de YEM** | `POST /validations/{id}/confirm` : saisir la **quantité réellement reçue** et la **référence de la transaction YEM** (obligatoire). | `POST /validations/{id}/reject` : motif obligatoire (transfert introuvable, données incohérentes). | `POST /validations/{id}/dispute` : le dossier passe en `disputed` avec commentaire. |
| **Paiement marchand** | `POST /validations/merchant-payments/{id}/confirm` | `POST /validations/merchant-payments/{id}/reject` | tranche un litige ouvert par le marchand |

Pour consulter le détail d'une réception : `GET /validations/{id}` et `GET /validations/pending`.

Toutes les décisions exigent une **confirmation récente par code e-mail** (step-up). Les confirmations sont idempotentes.

### 5.3 Ce que produit une confirmation de YEM

1. La réservation devient une consommation de quota.
2. La contre-valeur en FCFA devient due : le cours est celui **figé à la demande**.
3. Un versement (payout) est créé pour la Comptabilité et, selon la configuration, envoyé automatiquement au prestataire.

Si la quantité reçue dépasse la quantité demandée, la confirmation est refusée (`RECEIVED_EXCEEDS_REQUESTED`) : il faut placer le dossier en litige.

### 5.4 Interdictions

Le Validateur ne peut pas : fixer ou modifier le montant en FCFA, déclarer un versement payé, relancer un versement, modifier le bénéficiaire, ni modifier l'historique.

---

## 6. Comptabilité

**Permissions** : `yem_validations.view`, `payments.view`, `user_packs.view`, `payouts.view`, `payouts.retry`, `payouts.settle_manual`, `payouts.approve_manual`, `reports.view`, `reports.export`.

**Mission** : régler les contre-valeurs en FCFA et rapprocher les comptes.

### 6.1 Les versements dus

`GET /finance/payouts` liste les versements, filtrables par statut, route, moyen et bénéficiaire, avec une recherche par référence (`PO-…`). La réponse comporte un résumé : montant total encore dû, nombre de versements nécessitant une action, règlements manuels en attente d'approbation.

Un versement indique le bénéficiaire, l'opération d'origine (référence `EX-…` ou `RD-…`, quantité reçue, cours), le montant total / payé / restant, et la colonne **« YEM confirmé »**, qui reflète la décision du Validateur et ne peut pas être modifiée ici.

**Découpage par défaut** (paramétrable) :

| Montant dû | Route |
|---|---|
| jusqu'à 500 000 F | 1 envoi Mobile Money |
| 500 001 à 1 000 000 F | 2 envois |
| 1 000 001 à 1 500 000 F | 3 envois |
| au-delà de 1 500 000 F | règlement manuel (virement ou espèces) |

Un versement découpé reste **une seule obligation** avec plusieurs envois techniques.

### 6.2 Actions

| Action | Appel | Remarque |
|---|---|---|
| Consulter le détail (envois, règlements) | `GET /finance/payouts/{id}` | — |
| Interroger le prestataire pour mettre à jour un envoi | `POST /finance/payouts/{id}/refresh` | — |
| Envoyer un versement | `POST /finance/payouts/{id}/dispatch` | step-up |
| Relancer **uniquement les envois en échec** | `POST /finance/payouts/{id}/retry` | step-up. Un envoi déjà réussi n'est jamais renvoyé. |
| Enregistrer un règlement manuel (banque ou espèces) | `POST /finance/payouts/{id}/manual-settlements` | step-up. Le montant doit couvrir exactement le reste dû. |
| Voir les règlements en attente d'approbation | `GET /finance/manual-settlements` | — |
| Approuver ou rejeter un règlement manuel | `POST /finance/manual-settlements/{id}/approve` ou `/reject` | step-up. **Par une autre personne que celle qui l'a saisi.** |

### 6.3 Autres tâches

- Consulter les tentatives de paiement entrant et les webhooks reçus (`payments.view`).
- Consulter les packs des membres et leur journal de quota (`user_packs.view`).
- Consulter les rapports et exporter (voir §7.5).

### 6.4 Statuts d'un versement

`awaiting_dispatch` → en cours → `paid`. Autres états : `failed` (échec, relance possible), `manual_required` (règlement manuel nécessaire). Un échec partiel n'efface rien : les envois réussis sont figés, le reste dû est recalculé.

---

## 7. Administrateur

**Permissions** : `members.view`, `members.manage`, `staff.view`, `staff.manage`, `roles.view`, `user_packs.view`, `payments.validate_manual`, `settings.view`, `settings.update`, `audit.view`, `packs.view`, `packs.manage`, `yem_validations.view`, `payments.view`, `payouts.view`, `reports.view`, `reports.export`, `imports.manage`, `api_docs.view`.

### 7.1 Tableau de bord

| Écran | Appel |
|---|---|
| Accueil : période, KPI, tendance, série par jour, derniers paiements, validations en attente | `GET /admin/dashboard` |
| Chiffres clés (membres, total encaissé, éléments en attente, YEM en circulation) | `GET /admin/overview` |
| Santé de la plateforme (réservé aux droits de sécurité) | `GET /admin/ops/health` |

Chaque bloc du tableau de bord n'est renvoyé que si le compte a la permission correspondante.

### 7.2 Membres

| Action | Appel |
|---|---|
| Rechercher (nom, e-mail, numéro de membre, **Pernum**, raison sociale) et filtrer par statut, type, famille de pack | `GET /admin/members` |
| Voir le profil | `GET /admin/members/{id}` |
| Voir **la fiche complète en un appel** : profil, adhésion, packs avec solde marchand, 10 derniers paiements, échanges, paiements marchands et événements d'audit | `GET /admin/members/{id}/overview` |
| Suspendre ou réactiver un membre, avec motif de 5 caractères minimum ; les sessions du membre sont fermées ; action sensible (step-up) | `POST /admin/members/{id}/suspend` ou `/reactivate` |
| Voir les packs et le journal de quota des membres | `GET /admin/user-packs`, `GET /admin/user-packs/{id}` |
| Voir les paiements marchands et les comptes marchands | `GET /admin/merchant-payments`, `GET /admin/merchant-accounts/{id}` |

L'Administrateur **ne peut pas ajuster un quota** : cette correction est réservée au Superadmin.

### 7.3 Catalogue de packs

Cycle de vie d'un pack : `DRAFT` (brouillon, invisible) → `PUBLISHED` (souscriptible) → `SUSPENDED` (souscription bloquée) → `RETIRED` (retiré, les détenteurs conservent leur version) → `ARCHIVED`.

| Action | Appel |
|---|---|
| Lister et consulter les packs | `GET /admin/packs/plans`, `GET /admin/packs/plans/{id}` |
| Créer un pack | `POST /admin/packs/plans` |
| Créer une version (prix, quota, périodicité, cumul, fin de durée, remise, limites…) | `POST /admin/packs/plans/{id}/versions` |
| Publier une version | `POST /admin/packs/plans/{id}/versions/{version}/publish` |
| Supprimer une version non publiée | `DELETE /admin/packs/plans/{id}/versions/{version}` |
| Suspendre, retirer, archiver | `POST /admin/packs/plans/{id}/transition` |
| Dupliquer un pack | `POST /admin/packs/plans/{id}/duplicate` |

Une **version publiée est immuable** : pour changer les conditions, on crée une nouvelle version. Toutes les écritures exigent un step-up.

### 7.4 Paramétrage

| Action | Appel |
|---|---|
| Lister les paramètres, voir un paramètre | `GET /admin/settings`, `GET /admin/settings/{clé}` |
| Modifier un paramètre | `PUT /admin/settings/{clé}` |

Chaque modification crée une **nouvelle version** avec motif obligatoire (5 caractères minimum), auteur et, si besoin, une date d'entrée en vigueur future. On ne peut pas modifier le passé (`SETTING_RETROACTIVE_CHANGE`). L'Administrateur modifie les paramètres de niveau courant (taux, frais, moyens de paiement, durées, seuils…) ; les paramètres **critiques** sont réservés au Superadmin.

### 7.5 Rapports, exports, imports

| Action | Appel | Remarque |
|---|---|---|
| Synthèse d'une période : KPI, répartition par source et par moyen de paiement, top membres, activité YEM, versements | `GET /admin/reports/summary?month=YYYY-MM` ou `?from=&to=` | Période libre, 30 jours par défaut. |
| Version imprimable (« Imprimer → Enregistrer en PDF ») | `GET /admin/reports/summary/print` | — |
| Exporter en CSV : membres, paiements, souscriptions, paiements marchands | `GET /admin/exports/{type}` | step-up. CSV en UTF-8 avec BOM, séparateur `;`, ouvre directement dans Excel. |
| Lister ou générer un snapshot consolidé, le télécharger | `GET/POST /admin/snapshots`, `GET /admin/snapshots/{id}/download` | Le snapshot est versionné, avec une empreinte (`X-Content-SHA256`). |
| Charger un fichier consolidé, voir l'aperçu des différences, approuver ou annuler | `POST /admin/imports`, `GET /admin/imports/{id}`, `POST …/approve`, `POST …/cancel` | Jamais d'écrasement direct de la base : le fichier passe par une zone de contrôle (schéma, conflits, diff), puis est approuvé par une **autre personne**, puis appliqué en une transaction. |

### 7.6 Comptes internes et audit (dans les limites de l'Administrateur)

- Il peut lister et consulter les comptes internes, créer un compte interne et lui attribuer des rôles (`staff.manage`) — **jamais** le rôle Superadmin, ni sur un Superadmin.
- Il peut consulter la liste des rôles et des permissions (`roles.view`), mais pas les modifier.
- Il peut lire le journal d'audit métier (`GET /admin/audit-events`), pas le journal de sécurité.
- Il peut valider les paiements hors prestataire (`payments.validate_manual`), mais ne peut pas confirmer une réception de YEM.

---

## 8. Superadmin

Le Superadmin détient **toutes** les permissions. Il est le seul à pouvoir exercer les droits « réservés ».

| Domaine | Ce qu'il fait en plus | Appel |
|---|---|---|
| **Comptes internes** | Créer un compte interne (l'intéressé reçoit un code d'activation par e-mail, choisit son mot de passe via `POST /auth/staff/activate`), le suspendre, le réactiver, modifier ses rôles (avec motif). | `POST /admin/staff`, `PUT /admin/staff/{id}/roles`, `POST /admin/staff/{id}/suspend` / `reactivate` |
| **Rôles et permissions** | Créer un rôle, modifier ses permissions, le supprimer s'il n'est plus attribué. Les rôles système sont protégés. La plateforme **refuse** toute combinaison qui viole la séparation des tâches. | `GET /admin/permissions`, `GET /admin/roles`, `POST /admin/roles`, `PUT /admin/roles/{id}/permissions`, `DELETE /admin/roles/{id}` |
| **Paramètres critiques** | Modifier ce que l'Administrateur ne peut pas : Pernum de la CECT, prestataires de paiement et de versement actifs, seuils sensibles, activation des automatismes. | `PUT /admin/settings/{clé}` |
| **Prestataires** | Configurer les prestataires et leurs secrets (jamais affichés en clair après enregistrement). Le prestataire factice `fake` est refusé en production. | réglages `payment.inbound_provider`, `payment.payout_provider` et variables d'environnement |
| **Ajustement de quota** | Corriger manuellement le quota d'un pack ou le compte marchand d'un membre. Correction **compensatoire** et auditée, avec motif, jamais une réécriture. | `POST /admin/user-packs/{id}/adjustments`, `POST /admin/merchant-accounts/{id}/adjustments` |
| **Audit et sécurité** | Lire le journal d'audit complet et le **journal de sécurité** (connexions, codes, changements de rôle, sessions révoquées, signatures de webhook invalides). | `GET /admin/audit-events`, `GET /admin/security-events` |
| **Supervision** | Voir l'état des contrôles, la file de jobs, le dernier snapshot. | `GET /admin/ops/health` |
| **Secours** | Peut exécuter, à titre d'exception tracée, les actions des autres rôles (par exemple confirmer une réception de YEM). | — |

Le Superadmin ne peut pas supprimer le journal d'audit ni modifier un historique financier finalisé. Il ne peut pas non plus se retirer lui-même s'il est le dernier Superadmin actif.

---

## 9. Récapitulatif des droits

| Capacité | Membre | Marchand | Validateur | Comptabilité | Administrateur | Superadmin |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| Consulter / modifier son profil, gérer ses sessions | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Souscrire et utiliser ses packs | ✓ | ✓ | — | — | — | — |
| Vendre des YEM à la CECT (Pack Vendeur) | ✓ | — | — | — | — | — |
| Payer un marchand | ✓ | ✓ | — | — | — | — |
| Gérer ses QR, encaisser, demander un rééchange | — | ✓ | — | — | — | — |
| Valider un paiement hors prestataire | — | — | ✓ | — | ✓ | ✓ |
| Confirmer / rejeter la réception de YEM | — | — | ✓ | — | — | ✓ (secours) |
| Placer un dossier en litige | — | — | ✓ | — | — | ✓ |
| Consulter les versements | — | — | — | ✓ | ✓ | ✓ |
| Relancer, envoyer, régler manuellement un versement | — | — | — | ✓ | — | ✓ |
| Approuver un règlement manuel | — | — | — | ✓ (autre que le saisisseur) | — | ✓ |
| Gérer les membres (suspendre / réactiver) | — | — | — | — | ✓ | ✓ |
| Créer / versionner / publier des packs | — | — | — | — | ✓ | ✓ |
| Modifier les paramètres courants | — | — | — | — | ✓ | ✓ |
| Modifier les paramètres critiques et les prestataires | — | — | — | — | — | ✓ |
| Gérer les comptes internes | — | — | — | — | ✓ (hors Superadmin) | ✓ |
| Gérer les rôles et permissions | — | — | — | — | consultation | ✓ |
| Rapports | — | — | — | ✓ | ✓ | ✓ |
| Exports et snapshots | — | — | — | ✓ | ✓ | ✓ |
| Imports consolidés | — | — | — | — | ✓ | ✓ |
| Journal d'audit métier | propres traces | — | — | — | ✓ | ✓ |
| Journal de sécurité | — | — | — | — | — | ✓ |
| Ajuster un quota | — | — | — | — | — | ✓ |

> Les permissions sont **éditables** par le Superadmin dans les limites des invariants de séparation des tâches ; ce tableau reprend la configuration initiale des rôles système.

---

## 10. Sécurité des accès

| Sujet | Fonctionnement actuel |
|---|---|
| **Connexion** | Par Pernum ou e-mail et mot de passe. Les comptes internes reçoivent en plus un **code à 6 chiffres par e-mail** (usage unique, durée courte, tentatives limitées). |
| **Confirmation des actions sensibles (step-up)** | Validation d'un paiement ou d'un YEM, exports, imports, changement de destination de versement, rotation de QR, modification de paramètres, gestion des rôles et des comptes : code reçu par e-mail, valable 10 minutes par défaut. |
| **Sessions** | Cookie `HttpOnly` pour le site, jeton Bearer pour mobile et scripts. Chaque utilisateur voit ses appareils et peut les déconnecter. Une suspension ferme toutes les sessions. |
| **Mots de passe** | Hachés (algorithme contrôlé par `ops:golive`). Réinitialisation par code e-mail ; toutes les sessions sont alors révoquées. |
| **Accès aux données** | Chaque membre n'accède qu'à ses propres objets, contrôlé objet par objet côté serveur. |
| **Limitation de débit** | Connexion, codes, webhooks et routes sensibles sont limités (réponse `429`). |
| **Erreurs** | Format unique avec un `code` stable et un `correlation_id` pour le support. |

> **Écart connu avec le cahier des charges** : le cahier des charges demande une authentification multifacteur par application (TOTP) ou clé de sécurité (WebAuthn) pour les comptes internes, l'e-mail ne servant qu'au secours. Aujourd'hui, le second facteur est un code par e-mail. Le TOTP n'est pas encore implémenté.

---

## 11. Notifications par e-mail

La plateforme envoie des e-mails pour :

- les codes à usage unique : vérification de l'adresse, connexion du personnel, confirmation d'une action sensible, réinitialisation du mot de passe, activation d'un compte interne ;
- au membre : adhésion activée, paiement refusé (avec le motif), pack activé, rechargement Perso crédité, YEM reçus pour une demande, demande rejetée ;
- au marchand : paiement à confirmer ; au payeur : paiement confirmé ou non confirmé ;
- les alertes de supervision, aux adresses de `ops.alert_emails`.

Les e-mails passent par une file : un processus `queue:work` doit tourner en permanence.

---

## 12. Ce que fait le système tout seul

| Tâche | Effet |
|---|---|
| Crédit des périodes de packs | Ajoute le quota de chaque nouvelle période aux packs actifs. |
| Annulation des packs impayés | Annule les souscriptions jamais payées. |
| Expiration des réservations | Libère le quota des ventes (24 h par défaut) et des paiements marchands (60 min par défaut) restés sans déclaration. |
| Relais de la boîte d'envoi | Délivre les messages en attente de façon fiable, sans perte même si le prestataire est indisponible. |
| Rafraîchissement des versements | Réconcilie les versements en cours avec le prestataire. |
| Snapshots consolidés | Génère un fichier versionné avec empreinte après les nouvelles opérations. |
| Contrôles d'intégrité | Vérifie que chaque solde de quota ou marchand correspond à ses écritures, et que la chaîne du journal d'audit est intacte. Un écart est un **incident critique** : les versements doivent être gelés jusqu'à explication. |
| Supervision | Vérifie la santé de la plateforme et alerte par e-mail. |

---

## 13. Parcours de bout en bout (exemples)

### A. Un nouveau membre vend ses YEM

1. S'inscrit (personne physique), vérifie son e-mail, paie 25 000 F par T-Money (page FedaPay) → compte `active`.
2. Souscrit un Pack Vendeur, paie → pack `active`, quota crédité.
3. Enregistre sa destination de versement T-Money (code de confirmation e-mail).
4. Crée une demande de vente de 100 YEM → 100 YEM réservés, instructions de transfert.
5. Envoie 100 YEM au Pernum CECT depuis son portefeuille YEM, déclare l'envoi.
6. Le Validateur vérifie la réception sur le Pernum CECT, saisit la quantité reçue et la référence → quota consommé, versement créé.
7. La Comptabilité (ou l'envoi automatique) verse la contre-valeur sur le T-Money du membre → versement `paid`.

### B. Un client paie un marchand

1. Le marchand a un Pack Marchand actif, un Pernum Business et un QR actif affiché en boutique.
2. Le client (Pack Consommateur) scanne le QR, vérifie le nom du marchand, choisit 20 YEM → paiement `reserved`.
3. Le client transfère 20 YEM au Pernum du marchand, puis déclare l'envoi.
4. Le marchand confirme la réception → le quota du client est consommé et le solde éligible du marchand augmente.
5. Si le marchand ne reçoit rien, il signale « non reçu » : un Validateur arbitre.

### C. Un marchand se fait racheter ses YEM

1. Le marchand demande un rééchange de 500 YEM (≤ solde éligible) → réservation.
2. Il transfère les YEM au Pernum CECT, déclare l'envoi.
3. Le Validateur confirme → versement créé. Pour un gros montant (au-delà de 1 500 000 F par défaut), la Comptabilité enregistre un règlement manuel et une **autre personne** l'approuve.

### D. Un paiement en espèces

1. Le membre choisit « espèces » → reçoit les instructions et la référence `PAY-XXXXXXXX`.
2. Il paie au guichet de la CECT, puis clique « J'ai payé » avec la référence du reçu.
3. Un Validateur voit la ligne dans la file, saisit le montant reçu (égal au montant dû) et valide, après confirmation par code e-mail.
4. L'adhésion ou le pack est activé et le membre est notifié.

---

## 14. Ce que la plateforme ne fait pas

- Elle n'exécute **aucun transfert de YEM** ni ne se connecte au portefeuille YEM : aucune API officielle n'est supposée disponible.
- Elle n'émet pas de « YEM virtuels » : le quota ou le solde affiché est un droit suivi, pas une monnaie.
- Elle ne confirme jamais un paiement ou un transfert à partir d'une action du navigateur.
- Elle ne supprime jamais une écriture financière : toute correction passe par un mouvement compensatoire motivé et audité.
- Elle ne permet pas d'enregistrer un paiement hors ligne : toute écriture financière est validée côté serveur.
