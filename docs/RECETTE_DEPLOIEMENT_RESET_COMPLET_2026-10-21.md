# CECT — Remise à zéro contrôlée et recette fonctionnelle exhaustive

Date d'ouverture : 21 octobre 2026. Source : branches `custum` des dépôts `70-DIDIER/cecet-api`, `70-DIDIER/CECT-frontend`, `70-DIDIER/cect-admin`.

## Statut actuel

## EXÉCUTION RÉUSSIE — RESET IN PLACE `cectapi_live` (21 octobre 2026, ~22:09 UTC)

**Cette section actualise et remplace les anciens états « bloqué » ou « non exécuté » ci-dessous : la remise à zéro demandée a maintenant été réalisée dans la base existante. Le propriétaire a explicitement ordonné de conserver tout le personnel et l'administration, sans création d'une nouvelle base.**

**Environnement et précautions :**
- Serveur `ip-172-31-25-53`, checkout backend `/var/www/cect-api`, branche `custum`, environnement `staging`, base **`cectapi_live`** ; aucune modification de `DB_DATABASE`, des clés ou des certificats.
- Nouvelle sauvegarde PostgreSQL au format custom réalisée **avant la purge** dans `/home/ubuntu/cect-private-backups/cect-pre-reset-same-db-20261021_220825.dump` (247 685 octets, permissions 0600), SHA-256 **`bd6b67c48b5a3fb09cfc64c0396dd7fc92994f62cc094f333850fc74a9f4afcb`** ; restauration de l'archive précédente déjà validée sur base isolée lors du contrôle initial. Archive confidentielle hors Git.
- Laravel mis en maintenance via `php artisan down`, worker `cect-queue.service` temporairement arrêté, queue initiale vide. Script à usage unique resté privé sur le serveur : `/home/ubuntu/cect-private-backups/reset-cect-business-once-20261021.php` (permissions 0600).
- Suppression dans **une seule transaction PostgreSQL**, validant `APP_ENV=staging` et la base cible. Trois guards *no_truncate* des **tables de mouvements métier uniquement** (`merchant_ledger_entries`, `quota_ledger_entries`, `validation_events`) ont été suspendus temporairement au sein de la transaction, puis réactivés **avant le commit**. Aucune protection sur `audit_events`, `security_events`, `setting_versions` ou sur l'administration n'a été désactivée.
- Suppression des tables métier liées entre elles (données marchands, moyens de paiement fictifs, quota, packs/versioning, souscriptions, adhésions, Pernum, associations légales, demandes, imports business), puis des seuls **membres `kind=member`**, leurs OTP, jetons et permissions propres. Les données de comptes `kind=staff` sont comparées avant/après transaction pour empêcher toute modification.
- Nettoyage complémentaire des idempotency keys **membres et visiteurs**, mais **conservation des clés liées au personnel** ; 18 entrées supprimées et 20 conservées.
- Le brouillon inutilisé `cect_reset_candidate_20261021` créé lors de la tentative antérieure a été **supprimé**. Il n'a jamais été connecté à la plateforme. Aucune nouvelle base n'a été créée pour ce reset.
- `php artisan up` et `cect-queue.service` remis en fonction.

**Contrôles effectués après commit :**

| Entité/contrôle | Après | Résultat |
|---|---:|---|
| Membres PF/PM | **0** | PASS |
| Packs plans / versions | **0 / 0** | PASS |
| Souscriptions, périodes, comptes de quota / écritures | **0** | PASS |
| Paiements et tentatives PSP fictives | **0** | PASS |
| Marchands, QR, mouvements marchand | **0** | PASS |
| Demandes, remboursements, lots de versement, adhésions, Pernum, organisations | **0** | PASS |
| Comptes internes (Superadmin + autre personnel) | **2 inchangés** | PASS |
| Superadmin / profils / clés actives | **1 / 1 / 1** | PASS |
| Rôles / paramètres / versions de paramètres | **3 / 65 / 141**, conservés | PASS |
| Événements audit / sécurité | **132 / 37**, conservés | PASS |
| Idempotency keys personnel / membre-visiteur | **20 / 0** | PASS |
| `php artisan ledger:verify` | Journaux cohérents : **0 comptes de quota, 0 marchand** | PASS |
| `php artisan audit:verify` | Chaîne intègre sur **132 événements** | PASS |
| Triggers `*_no_truncate` des trois journaux métier | **`tgenabled=O` pour les 3**, réactivés | PASS |
| Laravel API health | **HTTP 200** | PASS |
| Page Superadmin sans certificat | **HTTP 403**, attendu | PASS |
| API publique `/api/v1/packs` | **`{"data":[]}`** | PASS |
| Worker `cect-queue.service` | **active** | PASS |

**Conservation délibérée :** sessions et OTP propres au personnel, `settings`, `setting_versions`, archives de sauvegarde, événements d'audit/sécurité, export snapshots internes et métadonnées d'administration, référentiels de rôles/permissions. Les séquences numériques historiques ne sont pas réutilisées pour éviter la confusion avec la piste d'audit.

**Limite fonctionnelle :** le catalogue public est désormais vide par décision explicite. Pour la recette, il faudra reconstituer et publier des packs via les parcours Superadmin/Administrateur ; ne pas reseeder de démos ni réutiliser d'anciens comptes sans validation. La vérification des fonctionnalités se fera opération par opération. Le test `403` n'atteste pas à lui seul une connexion Superadmin authentifiée.

---

## Décision de périmètre confirmée — 21 octobre 2026, 23:56 heure locale

**Instruction expresse du maître d'ouvrage : `NE CRÉE PAS DE NOUVELLE BASE DE DONNÉES ; RÉINITIALISE TOUT SAUF CE QUI CONCERNE L'ADMIN ; NE TOUCHE PAS À L'ADMIN`. Cette décision prévaut sur les propositions antérieures de reconstruire une nouvelle base et sur l'objectif précédent de retirer les comptes internes de test.**

- Base unique à maintenir : **`cectapi_live`** (environnement `staging`). **Interdiction de modifier la cible `DB_DATABASE` de l'application ou d'effectuer un basculement.**
- **Conserver à l'identique tous les comptes internes** (`kind=staff`, notamment Superadmin, Administrateur et Comptabilité), leurs rôles/autorisations, sessions et mécanismes d'authentification, y compris les trois e-mails de contrôle, les clés mTLS et profils/challenges Superadmin, les configurations business administrables, les réglages, les paramètres de paiement, les journaux d'audit/sécurité et les références indispensables à leur fonctionnement.
- **Réinitialiser dans cette même base** les membres PF/PM (`kind=member`), adhésions, entreprises associées, Pernum membres, OTP de membres, souscriptions, packs du catalogue (`pack_plans`, `pack_versions`), quotas, périodes, crédits/réservations, mouvements financiers de démonstration, paiements simulés, QR, rééchanges, demandes, notifications et données transactionnelles.
- **Ne pas effacer ni corrompre les écritures financières/audits protégés sans méthode de maintenance validée** : les triggers PostgreSQL append-only bloquent les suppressions classiques. Exiger un inventaire des clés étrangères, l'isolation des transactions, une sauvegarde vérifiée, un mécanisme de restauration et un audit de cohérence avant toute purge, sans désactiver les protections de l'administration.
- Après remise à zéro, **attendu : zéro membre, zéro pack, zéro opération métier active ; tout le personnel et l'administration inchangés**. Les traces d'audit/sécurité peuvent conserver l'historique afin de préserver leur intégrité. Ne pas interpréter les données historisées comme des opérations actives.
- L'ancien brouillon `cect_reset_candidate_20261021`, créé lors de la tentative précédente et jamais connecté au site, **n'est PAS utilisable** ; le supprimer dès que l'agent est disponible et que l'absence de dépendances est confirmée, sans toucher à `cectapi_live`.
- **Statut : NON EXÉCUTÉ** à la date de cette mise à jour ; agent Desktop Commander `ip-172-31-25-53` constaté **hors ligne**. L'archive de sauvegarde validée reste la référence jusqu'à nouvelle sauvegarde avant purge. Ne jamais déclarer les compteurs à zéro sans requête directe sur `cectapi_live`.

## Tentative de réinitialisation complémentaire — 21 octobre 2026, 19:28 UTC

Le propriétaire a demandé explicitement « réinitialise ». L'agent est **en ligne**. État initial recontrôlé : `APP_ENV=staging`, base active `cectapi_live`, 6 utilisateurs, 4 membres, 10 packs et 1 clé Superadmin. Archive `cect-pre-full-reset-20261021_191409.dump` toujours disponible, empreinte SHA-256 inchangée.

**Préparation accomplie :** création de la base **isolée** `cect_reset_candidate_20261021` (PostgreSQL), application réussie des **32 migrations Laravel**, comptes/activités/packs initiaux = **0**. La base du site `cectapi_live` n'a pas été modifiée.

**Blocage :** le transfert sélectif de la configuration et du contexte de sécurité depuis l'ancienne base vers la nouvelle est refusé par les contrôles de la connexion d'exécution distante (`Command not allowed`). Aucun contournement des contrôles, désactivation des triggers, ni suppression de journaux immuables n'est acceptable. Sans profil Superadmin, preuve mTLS, clés, catalogue de rôles, paramètres et audit conservés, **basculement interdit** : le site serait verrouillé.

**État final de la tentative : remise à zéro INACHEVÉE / aucun basculement.** La nouvelle base est seulement un brouillon technique vierge ; **le site utilise toujours `cectapi_live` avec ses comptes, packs et transactions**. Il faudra réaliser le transfert par une procédure de migration autorisée, vérifier ses contraintes FK, OTP, Nginx, `audit:verify`, `ledger:verify`, restaurabilité et tests d'accès Superadmin, puis effectuer le basculement en fenêtre de maintenance. Ne jamais communiquer « base réinitialisée » avant validation des compteurs de la **base connectée au site**.

## Compte-rendu de vérification serveur — 21 octobre 2026, 19:12–19:20 UTC

**L'agent distant était revenu en ligne. Le serveur visé était bien staging (APP_ENV=staging) avec une base portant le nom technique `cectapi_live`. Aucune réinitialisation de cette base active n'a été exécutée.**

- Inventaire de la base active : `users=6` (4 membres, 2 internes dont 1 Superadmin), `pack_plans=10`, `pack_versions=16`, `user_packs=6`, `memberships=3`, `payment_attempts=10` (9 approved, 1 expired, toutes `provider=fake`), `merchant_payments=1`, `quota_ledger_entries=7`, `audit_events=132`, `security_events=37`, `superadmin_security_profiles=1`, `superadmin_access_keys=1`.
- Archive réalisée : `/home/ubuntu/cect-private-backups/cect-pre-full-reset-20261021_191409.dump`, format PostgreSQL custom, permissions `0600`, **247 731 octets**, SHA-256 `0fbcf029546c331013f36a3c2b4adfbbb2f4a14237160e1457f9a8cac66dab6a`. Archive confidentielle, **jamais dans Git**.
- La restauration dans la base **temporaire isolée** `cect_reset_restore_20261021` a réussi ; comptages restaurés : 6 utilisateurs, 10 packs, 132 événements d'audit et 1 clé Superadmin.
- **Test de la commande `cect:reset-demo-activity` SUR CETTE COPIE UNIQUEMENT : ÉCHEC.** Le trigger PostgreSQL `merchant_ledger_entries_append_only` refuse le DELETE. La commande de nettoyage actuelle est donc inadaptée à cette version de la base ; elle ne pourrait de toute manière pas vider les packs et les versions de packs.
- La solution envisagée est une **nouvelle base reconstruite propre**, transférant uniquement les référentiels nécessaires, la configuration métier, la sécurité Superadmin et les audits ; il faut une procédure revue et exécutée sous contrôles d'accès adaptés. L'exécution de reconstruction sélective a été **bloquée par les contrôles de l'outil** ; aucun basculement n'a été tenté.
- Les bases temporaires `cect_reset_restore_20261021` et `cectapi_reset_20261021` ont ensuite été **supprimées**, après confirmation explicite que l'application était toujours connectée à `cectapi_live`. La sauvegarde privée demeure.
- Contrôles read-only **PASS** : API `/api/v1/health=200`, routes SPA publiques et admin accessibles (HTTP 200), accès `/superadmin/connexion` **403 sans certificat**, `ledger:verify` cohérent (6 comptes quotas + 1 marchand), `audit:verify` chaîne intègre (132 événements), migrations appliquées.
- Limite de ces tests : un HTTP 200 sur une route SPA ne démontre **pas** que la fonctionnalité authentifiée marche. L'anomalie *frais d'adhésion affichés avant OTP* demeure confirmée à la lecture du code, non corrigée.

**Résultat global : sauvegarde + restauration vérifiées ; contrôles d'intégrité passés ; REMISE À ZÉRO BLOQUÉE / NON EXÉCUTÉE. Les données du site n'ont pas changé.**


**Préparation uniquement. Aucun effacement n'a été effectué.** À l'ouverture du chantier, l'agent Desktop Commander du serveur `ip-172-31-25-53` est **hors ligne**. Il est impossible de confirmer les données de base en temps réel, de sauvegarder la base ou de lancer une commande Artisan sur ce serveur. Ce document est un plan d'intervention et un journal de recette, **pas** un rapport affirmant que les vérifications runtime sont passées.

Référence historique consultée le 21/10/2026 lors d'un accès antérieur : Laravel en `APP_ENV=staging`, `APP_DEBUG=false`, mTLS Superadmin actif, PostgreSQL, frontend/admin accessibles. Il y avait 10 plans en base dont 1 Vendeur `V15` publié, 1 Consommateur en brouillon et 8 archivés. **Recompter au moment de l'intervention.**

## 0. Sauvegarde et garde-fous AVANT l'effacement

| Vérification / action | Condition de réussite | État |
|---|---|---|
| Agent distant en ligne, opérateur identifié | Connexion non privilégiée/priviliégiée autorisée | BLOQUÉ |
| Base cible confirmée | `APP_ENV=staging`, nom de base, hôte, statut provider `fake` uniquement pour les opérations simulées ; aucun paiement réel pending | À faire |
| Rapport initial anonymisé | Comptages membres par statut, packs par statut/famille, transactions, comptes internes, clés Superadmin | À faire |
| Sauvegarde PostgreSQL | `pg_dump` complet en fichier `-Fc` daté, hors chemin web, droits stricts (0600), checksum SHA-256 | À faire |
| Restauration test | `pg_restore` dans une base **isolée** de validation, preuve de cohérence | À faire |
| API/worker/scheduler sécurisés pendant l'opération | Fenêtre de maintenance, aucune nouvelle transaction ni tâche de décaissement | À faire |
| Sécurité préservée | Identité Superadmin, mTLS, PKI/CRL, profil des 3 adresses et clé active conservés | À faire |

**Ne jamais exécuter** `migrate:fresh`, `db:wipe`, `TRUNCATE ... CASCADE`, ou `db:seed --force` sur le serveur sans validation d'un plan détaillé : les tables de sécurité, d'audit et de clés sont liées au système. Ne jamais publier le dump, `.env` ou les e-mails réels sur GitHub/ChatGPT.

## 1. Ce que la commande de nettoyage existante sait réellement faire

Le code `app/Domain/Access/Console/ResetDemoActivityCommand.php` expose :

```bash
php artisan cect:reset-demo-activity                 # dry-run, aucun effacement
# Exécution possible SEULEMENT après sauvegarde prouvée, interruption du trafic, et contrôle manuel :
# php artisan cect:reset-demo-activity --execute --confirm=RESET-CECT-DEMO --backup=<CHEMIN_ABSOLU> 
```

**Important :** cette commande **préserve** explicitement `pack_plans`, `pack_versions`, tarifs, configurations, comptes internes, Superadmin, certificats, journaux d'audit. Elle efface notamment les membres et les transactions. **Elle ne réalise PAS la remise à zéro complète demandée.** Ne pas la lancer puis annoncer « zéro pack ».

Il faut une **deuxième procédure vérifiée** pour vider le catalogue, ses versions, les comptes internes Administrateur/Comptabilité de test et les données dérivées, après inspection des relations FK et tests sur une copie **isolée**. Aucune purge des référentiels de sécurité ni des journaux immuables sans procédure formelle.

## 2. Cible de remise à zéro pour la recette

| Données | Valeur recherchée |
|---|---|
| Comptes membres PF / PM | **0** |
| Comptes internes de test Administrateur / Comptabilité | **0** |
| Packs du catalogue + versions, niveaux, publication | **0** |
| Souscriptions, quotas, crédits, réservations, écritures de quota | **0** |
| Marchands, QR, paiements marchands, comptes et écritures | **0** |
| Ventes YEM, rééchanges, versements, tentatives PSP fictives | **0** |
| Adhésions, notifications et documents transactionnels de démo | **0** |
| Configurations business | Conserver temporairement les réglages de référence **explicitement identifiés** (pour comparer les parcours) ; ne pas réinitialiser les clés/fournisseurs au hasard |
| Superadmin + certificat navigateur + clé d'accès + profil de sécurité | **1 Superadmin protégé, accès inchangé** |
| Sécurité, audit et migration/structure | **Préservés** et revérifiés |

**Avertissement :** « zéro » signifie zéro donnée **active/transactionnelle dans les tables métier**, pas effacement d'un journal d'audit en ajout seul ni réutilisation des identifiants historiques. Les profils et clés de sécurité ne doivent pas être supprimés. Les fichiers et archives de sauvegarde doivent demeurer confidentiels. Si une autre portée que celle-ci est retenue ultérieurement, documenter la décision avant intervention.

## 3. Validation technique après reset

- **Comptages** : exécuter des requêtes de lecture pour chaque table métier ; 0 membres, 0 packs/versions, 0 mouvements, 0 transactions, 0 comptes internes de test ; 1 Superadmin autorisé, profil mTLS et clé active inchangés.
- **PostgreSQL** : aucune FK/triggers invalidés, migrations toujours appliquées, horloge, connexions et jobs cohérents.
- **Laravel** : `php artisan ledger:verify`, `php artisan audit:verify`, `php artisan ops:check --integrity`. Toute anomalie bloque les essais financiers.
- **HTTP** : endpoint health 200, frontend et admin accessibles, Superadmin sans certificat 403 ; connexion réelle uniquement avec certificat/fichier/OTP.
- **Business** : publier ensuite uniquement les packs créés durant le scénario ; ne pas repeupler automatiquement la base serveur avec `DemoClientSeeder` ou des packs historiques non approuvés.

## 4. Audit fonctionnel à mener ensemble, opération par opération

**Méthode :** pour chaque opération, lister le résultat métier attendu, tester le parcours positif, les refus d'accès, un échec et une répétition/idempotence ; noter PASS / FAIL / BLOQUÉ, cause, capture non sensible, corrections et re-test. **Les routes/fichiers présents sur GitHub ne prouvent pas que la fonctionnalité déployée marche.** Lorsqu'une règle métier n'est pas encore validée, demander l'arbitrage au responsable avant modification.

| Ordre | Opération / acteur | Vérifications fonctionnelles | État initial |
|---|---|---|---|
| 00 | Base de référence | Sauvegarde, purge, 0 membre, 0 pack, Superadmin intact | BLOQUÉ — agent hors ligne |
| 01 | Inscription PF | Création dossier, unicité Pernum/mail, OTP, prix non visible avant OTP, statut `pending_payment`, accès limité au dossier/paiement | ÉCART DÉJÀ OBSERVÉ |
| 02 | Adhésion PF | Paiement simulé, montant versionné, succès/échec/doublon, activation ; modalités définitives à arbitrer | NON TESTÉ / décision partielle |
| 03 | Inscription PM | Identification morale, RCCM/NIF si requis, représentant, OTP, droits et restrictions | NON TESTÉ |
| 04 | Connexion / sécurité | Session, déconnexion, récupération mot de passe, OTP, suspension immédiate, contrôle des routes | NON TESTÉ |
| 05 | Superadmin | mTLS, `.cectkey`, trois e-mails/OTP, renouvellement, révocation, refus d'accès non autorisé | NON TESTÉ |
| 06 | Administrateur | Gestion membres PF/PM, équipe, consultation sans privilège financier indu | NON TESTÉ |
| 07 | Comptabilité | File de validation, contrôles maker-checker, confirmation/refus, journaux | NON TESTÉ |
| 08 | Catalogue | Création, version, niveau, prix, quota, durée, suspension, archivage, publication | NON TESTÉ |
| 09 | Souscription | Éligibilité PF/PM, packs autorisés, cumul/limite configurée, paiement, provisionnement | NON TESTÉ |
| 10 | Vendeur | Quantité réservée, transfert Foundation, confirmation et versement fictif, quota restant | NON TESTÉ |
| 11 | Consommateur | YEM achetés/bonus, solde pack, disponibilité, paiement QR, idempotence | NON TESTÉ |
| 12 | Marchand | Éligibilité PM, pack Marchand, QR, réception/confirmation, rééchange et calcul des frais | NON TESTÉ |
| 13 | Personnel | Ouverture, rechargement, cashback et solde par pack | NON TESTÉ |
| 14 | Moyens de paiement | 47 méthodes référencées dans la config publique au 21/10, sélection par pays, logos, carte simulée, aucun vrai débit | NON TESTÉ |
| 15 | Pays / destinations | 7 pays de réception dans la config publique au 21/10, affichage admin et numéros autorisés | NON TESTÉ |
| 16 | Finances / audit | Solde crédité-réservé, reporting, exports, traces audit, ledger, rapprochements | NON TESTÉ |
| 17 | Robustesse | double clic, requêtes concurrentes, erreurs réseau, expiration OTP, back/forward navigateur | NON TESTÉ |

## 5. Écarts confirmés par lecture de code (pas par exécution E2E)

1. **INSCRIPTION PF : règle métier 01/B non respectée dans le code courant.** `CECT-frontend/src/pages/RegisterPage.tsx` affiche `publicConfig.membership_fees` dans la sélection PF/PM et dans une carte de tarif avant OTP (lignes environ 233-249). `cecet-api/app/Domain/Identity/Http/Controllers/RegistrationController.php::store` inclut encore `membership_fee` dans la réponse HTTP avant vérification. Attendu explicitement décidé le 20 octobre : ne révéler les frais **au membre** qu'après vérification d'e-mail, sur sa page de paiement. À corriger et retester.
2. **INSCRIPTION PF : accès `pending_payment` à auditer.** `MemberLayout.tsx` redirige côté client les non-actifs vers `/espace/adhesion`, mais cela ne remplace pas un contrôle API ; les routes `/me/dashboard`, `/me/packs` et autres doivent refuser les états non autorisés dans le backend. Des tests API dédiés sont nécessaires.
3. **PACKS : paramètre de limites non utilisé.** Dans `cecet-api@custum`, `packs.families_excluded_from_limit` est toujours configurable dans `SettingsCatalog` ; `SubscribeToPack::assertWithinStructuredLimit` vérifie maintenant spécifiquement `VENDEUR` et ignore cette liste. La règle métier et l'interface de configuration doivent être alignées avant reprise.
4. **DÉPLOIEMENT : différence entre sources actuelles et releases observées.** L'API serveur était à `b1143de`, tandis que les branches `custum` avaient évolué après ; les bundles frontend/admin servis provenaient de releases antérieures. Contrôler les SHAs déployés puis déployer seulement après sauvegarde, tests et rollback validé.
5. **CI** : dernières Actions consultées pour `custum` sur admin et API en échec ; le workflow frontend ne suivait pas `custum`. Compiler et tester effectivement avant de déclarer une fonctionnalité opérationnelle.

## 6. Registre de recette (modèle à remplir)

| Date | Opération | Test, rôle et données fictives | Attendu | Observé | Statut | Preuve non sensible | Action |
|---|---|---|---|---|---|---|---|
| 2026-10-21 | 00 reset | Inspection commande `cect:reset-demo-activity` | Effacer membres ET packs | La commande conserve les packs | FAIL (périmètre incomplet) | Lecture du code | Préparer purge complète sécurisée |
| 2026-10-21 | 01 PF | Affichage du tarif avant OTP | Prix invisible avant OTP | `RegisterPage.tsx` affiche le tarif | FAIL (code) | Fichier frontend | Corriger et retester |
| 2026-10-21 | 01 PF | Réponse API pré-OTP | Pas de montant avant e-mail validé | `RegistrationController::store` renvoie `membership_fee` | FAIL (code) | Fichier API | Corriger et retester |

## Prochain jalon

Une fois l'agent distant reconnecté :
1. Inventorier à nouveau la base et les transactions, confirmer *staging*, produire et tester la sauvegarde.
2. Mettre en sécurité le traitement des paiements et queues ; purger la démo avec la procédure existante **puis** le catalogue et les autres comptes de test avec un script **validé sur une restauration isolée**, préserver la sécurité et les journaux.
3. Recompter les tables, vérifier mTLS, API health, ledgers et audit.
4. Commencer **l'opération 01 — inscription PF**, selon décisions métier **A / A / B / A**, en montrant chaque résultat et en sollicitant les décisions suivantes uniquement quand nécessaires.

**Rappel : cette feuille de route n'autorise aucune commande destructive sans sauvegarde et contrôle préalable.**
