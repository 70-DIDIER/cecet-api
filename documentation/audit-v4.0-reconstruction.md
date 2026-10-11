# Audit de reconstruction — Cahier des charges CECT/YEM v4.0

Date de revue : 11 octobre 2026  
Branche cible : `joseph`  
Référence : Cahier des charges + dossier de conception v4.0

## Principe

Cette matrice remplace les validations optimistes non prouvées. Un élément n'est **PROUVÉ** que lorsqu'il existe dans le code, qu'il est protégé côté backend et qu'un test automatisé ou une recette reproductible démontre le comportement attendu.

Statuts :
- **PROUVÉ** : implémentation + contrôle backend + test/preuve.
- **PARTIEL** : structure présente mais couverture, UX ou preuve incomplète.
- **MANQUANT** : exigence absente ou non exploitable.
- **À RATIFIER** : le moteur doit rester configurable ; décision métier/contractuelle encore ouverte.

## 1. Synthèse exécutive

| Domaine | Statut | Observation |
| --- | --- | --- |
| Identité physique/morale | PARTIEL | Modèles, inscription et organisation séparée présents. Refaire la recette E2E complète sur staging. |
| Adhésion | PARTIEL | Intent/attempt et activation serveur présents. Recette = provider interne ; production non validée. |
| Catalogue Packs | PROUVÉ | Plans, versions immuables, lifecycle et éligibilité backend présents. |
| Pack Vendeur | PARTIEL | Réservation, déclaration, validation et payout existent. Recette E2E complète à rejouer. |
| Pack Consommateur | PARTIEL | Quota et paiement marchand présents. Vérifier tous niveaux/remises et UX complète. |
| Pack Personnel | PARTIEL | Modèle sans période/quota fixe + top-up existe. Les règles métier finales restent ouvertes. |
| Pack Marchand | PARTIEL | QR, ledger marchand, encaissement et rééchange présents. Catalogue M1–M10 non ratifié. |
| Validateur | PARTIEL | Workflow de confirmation/rejet/litige présent ; UI et preuves exactes à recetter. |
| Comptabilité | PARTIEL | Payouts, retries et maker-checker présents ; parcours complet à recetter. |
| Notifications in-app | PROUVÉ CODE | Centre persistant + ownership + état lu/non-lu + tests ajoutés ; déploiement staging à exécuter. |
| Documents membre | PROUVÉ CODE | Centre privé ajouté : reçu d’adhésion, fiche d’adhésion, relevé de packs et relevé d’activité ; téléchargement audité. Déploiement staging à exécuter. |
| Rapports PDF/XLSX/CSV | PARTIEL | Génération présente ; volumétrie/asynchrone et UAT à vérifier. |
| Snapshots / imports | PARTIEL | Endpoints présents ; tests de conflits et restauration opérationnelle à rejouer. |
| Audit métier | PARTIEL | Journal présent ; immutabilité/tamper evidence à revalider sur DB réelle. |
| RBAC | PARTIEL | Permissions fines + incompatibilités présentes. Tests négatifs exhaustifs manquants. |
| MFA / sessions | PARTIEL | OTP/step-up/sessions présents. MFA TOTP/WebAuthn obligatoire en production non prouvé. |
| CI/CD | MANQUANT | Aucun statut CI n'est actuellement attaché aux commits de la branche. |
| Sauvegardes / PRA | MANQUANT À PROUVER | Exigence documentée mais aucune preuve récente de restauration n'est attachée à la release. |
| Observabilité | PARTIEL | Health/security events présents ; métriques et alertes production à valider. |
| Base de données cible | INCOHÉRENCE MAJEURE | Le cahier cible MySQL ; le code actuel contient du SQL PostgreSQL spécifique. |
| PSP production | À RATIFIER | Aucun provider réel ne doit être activé avant validation contractuelle. |

## 2. Incohérences prioritaires à éliminer

### P0 — Architecture DB

Le cahier cible MySQL, alors que plusieurs requêtes utilisent des primitives PostgreSQL : séquences `nextval`, agrégats `FILTER`, `to_char`. La reconstruction doit soit :
1. rendre le code SQL portable et migrer vers MySQL, conformément au cahier ; soit
2. faire ratifier officiellement PostgreSQL comme changement d'architecture et mettre à jour le cahier.

Aucune bascule de base de données ne doit être faite sans sauvegarde, plan de migration, rollback et recette.

### P0 — Preuve de conformité

Le fichier historique de conformité marque plusieurs blocs `OK` sans test E2E ou preuve opérationnelle récente. Chaque critère AC-01 à AC-25 doit recevoir :
- test automatisé lié ;
- scénario de recette staging ;
- résultat daté ;
- commit/release testé ;
- preuve d'échec négatif lorsque pertinent.

### P0 — Paiements de recette

Les **moyens métier** restent T-Money, Flooz, virement bancaire et espèces. En staging, seule l'exécution est simulée :
- aucun débit réel ;
- aucun provider live ;
- les scénarios succès/refus/annulation/timeout restent testables ;
- banque/espèces restent dans le workflow manuel contrôlé ;
- le domaine ne doit pas être renommé en moyens artificiels `SIM_*`.

### P1 — Documents membre

Socle ajouté : reçu d'adhésion, fiche d'adhésion, relevé de packs et relevé d'activité avec ownership backend et téléchargement audité. À compléter ensuite par les reçus de pack/top-up unitaires et la politique de rétention/stockage des pièces justificatives.

### P1 — Notifications

Le centre persistant est désormais ajouté :
- notifications privées par utilisateur ;
- lecture/non-lu ;
- historique dans l'espace membre ;
- email conservé comme canal secondaire.
À compléter : quota périodique, expiration, litige, payout et documents prêts.

### P1 — Back-office packs

Le moteur backend est robuste mais l'interface doit exposer clairement :
- famille, code, niveau, statut ;
- version courante et historique ;
- éligibilité physique/morale ;
- prix, période, cycles, quota, cumul, politique fin de durée ;
- min/max opération ;
- règles spécifiques famille ;
- duplication ;
- publication/suspension/retrait/archivage ;
- prévisualisation des impacts avant publication.

### P1 — Dashboards par rôle

Chaque espace doit refléter strictement sa responsabilité :
- Validateur : réception YEM seulement ;
- Comptabilité : dette FCFA/payout/rapprochement, sans modification de validation YEM ;
- Admin : membres, packs, contenus, paramètres métier et rapports ;
- Superadmin : gouvernance, RBAC, sécurité, providers, paramètres critiques, audit, continuité.

## 3. Critères AC-01 à AC-25

| ID | Statut | Preuve / travail restant |
| --- | --- | --- |
| AC-01 | PARTIEL | Inscription + OTP présents ; E2E staging à rejouer. |
| AC-02 | PROUVÉ CODE | Contraintes email/Pernum présentes ; test de concurrence à conserver. |
| AC-03 | PARTIEL | Activation serveur présente ; simulation staging à tester après déploiement. |
| AC-04 | PROUVÉ CODE | 25 000 / 200 000 configurables et versionnés. |
| AC-05 | PROUVÉ CODE | Pack plan/version sans changement de code. |
| AC-06 | PROUVÉ CODE | UserPack pointe vers PackVersion achetée. |
| AC-07 | PROUVÉ CODE | Limite configurable, Personnel excluable. |
| AC-08 | PROUVÉ CODE | Personnel sans période/quota fixe, top-up à la demande. |
| AC-09 | PARTIEL | Réservation atomique présente ; stress concurrence à rejouer. |
| AC-10 | PROUVÉ CODE | Déclarer envoyé ne crée pas la dette. |
| AC-11 | PARTIEL | Permission Validateur présente ; tests négatifs exhaustifs à compléter. |
| AC-12 | PROUVÉ CODE | Consommation avant payout, retry ne recrédite pas quota. |
| AC-13 | PARTIEL | QR serveur présent ; tests QR falsifié/rotation à compléter. |
| AC-14 | PARTIEL | Comptabilité lit le payout ; UI à recetter pour absence de mutation YEM. |
| AC-15 | PARTIEL | Payout planner présent ; automatisme dépend politique/provider. |
| AC-16 | PROUVÉ CODE | Retry limité aux items FAILED. |
| AC-17 | PROUVÉ CODE | T-Money/Flooz/Virement/Espèces conservés, exécution simulée en staging. |
| AC-18 | PROUVÉ CODE | Rapports période libre présents. |
| AC-19 | PARTIEL | Snapshots présents ; déclenchement systématique sur mutations à auditer. |
| AC-20 | PARTIEL | Import staging/diff présent ; tests conflit/rollback à rejouer. |
| AC-21 | PARTIEL | Audit/correlation présents ; couverture de toutes actions sensibles à mesurer. |
| AC-22 | MANQUANT À PROUVER | MFA fort production non validé. |
| AC-23 | PARTIEL | Webhook infra présente ; provider production non ratifié. |
| AC-24 | PARTIEL | Ownership largement appliqué ; campagne IDOR systématique à exécuter. |
| AC-25 | MANQUANT À PROUVER | Test de restauration documenté absent de la release actuelle. |

## 4. Definition of Done d'une brique

Une brique n'est terminée que si :
1. backend source de vérité ;
2. autorisation deny-by-default ;
3. idempotence si mutation financière ;
4. transaction/verrouillage si solde/quota ;
5. audit ;
6. frontend responsive ;
7. tests unitaires + intégration + négatifs ;
8. documentation ;
9. déploiement staging ;
10. scénario E2E rejoué avec preuve.

## 5. Ordre de reconstruction

1. P0 cohérence architecture/DB + CI.
2. P0 E2E identité/adhésion/paiement simulé.
3. P1 Back-office Packs complet.
4. P1 Vendeur + Validateur + Comptabilité.
5. P1 Consommateur/Personnel + QR.
6. P1 Marchand + rééchange.
7. P1 Documents + Notifications.
8. P2 reporting/snapshots/imports.
9. P2 observabilité/backups/PRA.
10. P3 hardening, DAST, pentest et UAT avant toute production réelle.
