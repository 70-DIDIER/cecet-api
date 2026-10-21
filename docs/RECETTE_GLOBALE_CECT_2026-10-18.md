# CECT — Recette approfondie et simulations du 18 octobre 2026

> État : **EN COURS / PAS D'HOMOLOGATION FINANCIÈRE**. Ce document distingue les validations acquises des scénarios à corriger. La suite globale initiale ne satisfait pas encore tous les tests.

## Périmètre de recette
- Adhésion PF et PM, tarifs versionnés, inscription et contrôle du Pernum.
- Répartition des permissions entre SuperAdmin, Administrateur, Comptabilité, Vendeur/Consommateur PF, Marchand PM.
- Authentification mTLS SuperAdmin ; accès Comptabilité/Administrateur via OTP commun envoyé à trois adresses de sécurité, horaires individuels et validation de rôle.
- Catalogue versionné Vendeur, Consommateur, Personnel, Marchand ; deux souscriptions Vendeur simultanées, même formule permise ; Consommateur non plafonné ; Personnel rechargeable.
- Simulations de paiements, états de souscription, frais entrant, notifications et webhooks.
- Crédits de quota, reports, réservations, dépenses/cessions, ajustements, annulations, double clic et transferts concurrents.
- Paiements QR marchand, transferts YEM déclarés, réception attestée, solde rééchangeable, rachats, litiges.
- Reversements Mobile Money/virements/espèces, découpage en plusieurs items, limites, retries, perte de réponse fournisseur, frais bénéficiaire/coopérative, autorisation maker-checker.
- Versions tarifaires, exports, reporting, sécurité API, archivage, audit, réconciliation et reset demo.

## Séparation des environnements
- Base interactive du site : `cectapi_live` sur environnement de démonstration. **Ne pas injecter de charges synthétiques dans cette base.**
- Base PostgreSQL des tests automatisés : `cectapi_test`.
- Sauvegarde complète de la base de démonstration avant recette : `cectapi-live-before-ultra-recette-20261018.dump`, 241 602 octets ; empreinte SHA-256 `93021f3ff658e68c0c233ee460ef3424cb356c1f76458b606471042e70d5e63c`. Accès disque limité. Ne pas publier l'archive ni l'exporter sur GitHub.
- Réinitialisation finale : utiliser la commande sécurisée `cect:reset-demo-activity`, exclusivement sur staging, avec l'archive vérifiée dans le répertoire autorisé, confirmation explicite et fermeture temporaire du trafic commercial. Elle doit préserver SuperAdmin, clefs et certificats, paramètres et historique de sécurité, packs configurés, taux. Valider le catalogue après reset.

## Premiers résultats mesurés
| Campagne | Résultats | Interprétation |
|---|---|---|
| Première suite PostgreSQL globale | 398 passés, 184 échoués, 5 180 assertions | Bloquante : nombreux scénarios anciens `validateur`, plusieurs assertions de 500 FCFA/YEM au lieu de 510, politiques de permissions changées. |
| Régression souscription (nouvelle règle) | 21 tests passés, 157 assertions | Deux Vendeur du même plan, refus du troisième, Consommateur répété, annulation, paiement, versions et confidentialité. |
| Sécurité Comptabilité/Administrateur et reversements | 25 tests passés, 161 assertions | OTP à trois adresses, durée, horaires, protections ; décaissement, reprise et maker-checker, sous le mode test (sans e-mails réels). |
| Simulation volumétrique PostgreSQL | 100 000 comptes synthétiques, 1 000 000 opérations, 500 000 cashback, 0 bonus interdit, 0 solde négatif, 1,952 s | Tables TEMP puis rollback ; **pas** un million de transactions HTTP ni un benchmark de capacité de production. |

## Corrections codées
1. Migration de fusion Validateur -> Comptabilité compatible avec une installation vierge où les rôles sont initialisés après les migrations.
2. Historique des scénarios Validateur représenté uniquement par un alias de fixture Comptabilité, **sans recréer le rôle dans la base de production**.
3. Suite PHPUnit isolant les drapeaux mTLS/guardian de production ; tests explicites mTLS inchangés.
4. Limite de deux souscriptions **Vendeur** ; un membre peut souscrire deux fois à la même formule Vendeur, plusieurs Consommateur, et recharger Personnel.
5. Solde YEM de consommation calculé sans agréger les **quotas de cession Vendeur**.
6. Nouveaux tests sécurité pour connexion staff et pour les horaires / permissions à exécuter.
7. Tests de taux révisés : cours achat vendeur 510 FCFA/YEM, avec ventilation des paiements 510 000, 765 000, 1 224 000 et 1 632 000 FCFA.

## Écarts critiques à clarifier par le métier
- Le taux de cession Marchand figurant dans l'historique est **610 FCFA/YEM** ; une autre règle évoque **650 FCFA/YEM moins 3 %**. Les deux sont incompatibles. Pour une vente initiale de 650 FCFA/YEM assortie de 5 % de cashback, une compensation de **650 × (1 - 3 %) = 630,5 FCFA par YEM** donnerait une contre-valeur de **662,025 FCFA** pour les **1,05 YEM distribués**, soit un écart négatif **12,025 FCFA par YEM acheté** avant autres recettes et charges. Au taux de 610 FCFA/YEM, le même calcul donne **640,5 FCFA** de sortie et 9,5 FCFA de différence positive, sans préjuger du coût des stocks YEM ni des frais réels. **Interdit d'afficher un modèle automatiquement rentable sans ratification.**
- La base de présentation observée comporte **7 packs et les 7 sont archivés**, alors que le catalogue historique de référence prévoyait 25 formules. Ne pas republier des engagements financiers sans approbation des niveaux, tarifs, quotas et échéanciers.
- Comptabilité existante encore en état `pending_activation` ; aucun Administrateur ordinaire prêt à se connecter n'a été constaté. Éviter d'activer le nouveau parcours staff sans préparer les comptes et valider la réception sur les trois boîtes.
- Les versements sur carte bancaire nécessitent un prestataire qui prend en charge les décaissements et un token de bénéficiaire ; n'exiger ni ne conserver des PAN/CVV bruts.

## Décisions à soumettre à l'utilisateur
1. Marchand : 610 FCFA/YEM ou 650 moins 3 %, frais/dates/seuils exacts ; bonus maximum compatible avec la politique de liquidité.
2. Catalogue : faut-il republier les 25 historiques avec prix et quotas à valider, ou conserver 7 nouvelles formules à confirmer ? Quels packs rendre visibles lors de la présentation ?
3. Après remise à zéro : doit-on afficher zéro membre et zéro transaction, ou trois comptes fictifs « client PF », « client PM Marchand », « Comptable » clairement identifiés comme démo, et des scénarios contrôlés ?
4. Comptabilité/Administrateur : noms, adresses professionnelles et horaires d'accès, y compris week-ends et périodes de nuit.
5. Quel événement déclenche définitivement le crédit YEM et le paiement fiat : webhook PSP simulé, confirmation externe YEM Foundation, confirmation manuelle Comptabilité avec preuve ?
6. Quel plafond de cashback et quelle règle d'arrondi pour les packs Consommateur et Personnel ? Quels quotas mensuels et politique de report ?
7. Rééchange Marchand : contrôle preuve QR, validation du réel transfert, délai, frais, réutilisation en stock et restrictions en cas de litige.

## Recette de sortie / sécurité
- Ne pas annoncer « 100 % vérifié » avant une suite globale entièrement verte, des tests d'API réels en préproduction, des tests d'end-to-end navigateur et des contrôles d'intégrité ledger.
- Exécuter `php artisan ledger:verify` sur staging avant reset puis après reset.
- Préserver le certificat et le fichier d'accès du SuperAdmin ; ne jamais déposer une clé privée dans GitHub, dans des logs ou dans un export demo.
- Faire des sauvegardes et une marche arrière testée avant tout remplacement des fichiers UI ou activation du contrôle de connexion staff.


## Addendum urgent QA v5.9 — correctifs déposés pendant l'indisponibilité du serveur

**État : code commité, non redéployé, suite globale non revalidée.** La machine Remote Desktop `ip-172-31-25-53` est signalée hors ligne par le connecteur lors de la reprise. Il est donc interdit de déclarer la recette terminée, les interfaces validées ou l'environnement de présentation nettoyé.

1. **OTP personnel** : accès réservé aux utilisateurs actifs portant explicitement `administrateur` ou `comptabilite` ; les trois destinataires sont choisis depuis le profil du **SuperAdmin configuré**, jamais depuis le profil le plus récemment modifié ; une requête sans session navigateur ne consomme plus un OTP de connexion valide. L'ancienne activation par mot de passe renvoie 404 lorsque l'accès guardian est actif.
2. **SuperAdmin unique** : les opérations de création ou de changement de rôle du personnel refusent désormais la création d'une seconde identité SuperAdmin via les API administratives ordinaires. Aucune suppression ni modification de l'identité actuellement configurée n'a été effectuée.
3. **Régression automatisée sur branche `custum`** : workflow GitHub `.github/workflows/qa-custum.yml`, base PostgreSQL éphémère, analyse de la suite fonctionnelle complète et calcul synthétique de 1 million d'opérations via une table temporaire ; aucune écriture vers la base CECT de présentation. Les résultats GitHub Actions doivent être consultés et les échecs traités avant déploiement.
4. **Mise à jour des tests de rôles et membres** : trois rôles internes actuels, comptabilité ayant les droits de validation, deux packs Vendeur au maximum ; interdiction du deuxième SuperAdmin ; nouveau test de non-agrégation des YEM Vendeur dans le solde utilisable chez un Marchand.
5. **Tests de garde renforcée** : test d'un rôle Staff non autorisé ; configuration explicite de l'identité SuperAdmin dans les jeux de test (pour vérifier la liaison aux trois adresses exactes).
6. **Point à reprendre en priorité dès retour du serveur** : récupérer `/tmp/cect-qa-fullsuite-v2-20261018.log` et le code retour, exécuter à nouveau les suites, mettre à jour le schéma/les builds sans modifier les clés mTLS, vérifier `ledger:verify`, relever les packs réellement publiables, puis seulement décider de la remise à zéro sous backup.

**Aucune approbation fonctionnelle ou financière n'est déduite du simple commit.** Cette section documente l'avancement et les blocages réels.


## Validation du 20 octobre 2026 — bilan mesuré, sécurité et décisions en attente

### Résultats réellement obtenus
- **Suite backend complète : 593 tests réussis, 0 échec, 6 494 assertions**, exécutée avec PHPUnit/Pest contre la base `cectapi_test` (preuve locale persistante : `/home/ubuntu/cect-qa-results/full-20261020.log` ; exit code 0).
- Le test supplémentaire de disponibilité de présentation `tests/Feature/DemoReadinessTest.php` a réussi (1 test, 9 assertions), après la grande campagne. Il garantit que le diagnostic n'altère ni utilisateurs, ni paiements, ni données d'audit.
- Tests cryptographiques complémentaires dans `70-DIDIER/cect-admin/scripts/test-superadmin-key-crypto.cjs` : génération d'une clé RSA, export privé au format **.cectkey v2 chiffré**, déchiffrement après phrase secrète, rejet des phrases fausses et fichiers modifiés, signature vérifiable par RSA, conversion d'un ancien .cectkey v1 conservant la même paire de clés et le même identifiant. Compilations TypeScript et Vite réussies.
- Révisions UI Admin et Espace membre publiées sur les chemins HTTPS existants ; dernier Admin chiffré `/home/ubuntu/cect-releases/admin-qa-v63-encrypted-superadmin-20261020`. Vérifications `200` pour portail, inscription, API et bundles, `403` pour endpoints SuperAdmin **sans certificat client**.
- Base de démonstration non réinitialisée : chaîne d'audit cohérente (**131 événements**) et comptes marchands/quotas cohérents (**1 marchand, 6 comptes de quota**) selon les commandes `audit:verify` et `ledger:verify`.

### Corrections métier confirmées
1. **Cession Marchand :** l'API de rééchange utilise désormais par défaut `rates.transfer_xof_per_yem` = **610 FCFA/YEM** ; ne pas confondre avec `rates.purchase_xof_per_yem` = **510 FCFA/YEM** réservé au Vendeur. Le taux appliqué est figé dans chaque demande créée : **ne pas recalculer rétroactivement** les cessions précédentes. L'option `650 moins 3 %` n'est **pas** validée.
2. **Circulation des YEM :** seules les disponibilités des familles Consommateur et Personnel sont prises en compte dans l'indicateur de circulation du tableau de bord ; les quotas des Vendeurs ne constituent pas des YEM disponibles pour payer.
3. **Encaissements :** différencier explicitement paiements confirmés **réels** et paiements de packs validés par **passerelle fictive** ; ne pas gonfler le total des FCFA collectés avec les simulations.
4. **Permissions :** suppression fonctionnelle du rôle Validateur, missions fusionnées dans Comptabilité ; modifications des cours et frais d'adhésion réservées au SuperAdmin ; un deuxième SuperAdmin ne peut pas être créé via le module du personnel.
5. **Certificat et clé :** mTLS demeure obligatoire sur les chemins SuperAdmin. La nouvelle clé `.cectkey` (version 2) protège sa clé privée avec **AES-256-GCM** et PBKDF2-SHA256 (600 000 itérations ; phrase secrète au moins 14 caractères). L'ancien fichier version 1 en clair reste pris en charge **uniquement pour assurer la compatibilité et permettre une migration locale**. L'utilisateur doit télécharger la version chiffrée, vérifier son fonctionnement, puis détruire les copies anciennes non chiffrées. Aucun secret ni clé privée n'a été déposé dans Git.
6. **Horaires et OTP personnel :** backend codé/testé mais **non encore activé** sur la démonstration tant qu'il n'existe pas de comptes professionnels actifs pour les deux rôles et que les trois boîtes sécurisées n'ont pas été validées. Ne jamais rendre le nouveau login obligatoire en l'absence d'identités capables de l'utiliser.

### État de préparation du serveur (contrôle en lecture seule)
Commande : `php artisan cect:demo-check --json` (le code 1 signifie **présentation incomplète**, pas une panne).
- **4 membres** dans la base de staging au moment du relevé (non supprimés) ;
- **1 SuperAdmin**, 1 profil de sécurité et 1 clé active ;
- **10 fiches de pack au total**, dont 1 publiée : Vendeur **V15**. **0** Consommateur, **0** Personnel et **0** Marchand publiés ; 
- **0 Administrateur ordinaire actif**, **0 Comptable actif** ; `staff_guardian.enabled = false` ;
- états exacts du catalogue : 8 archives, 1 brouillon, 1 publiée.

**Blocages métier à arbitrer par le SuperAdmin** : choix et publication des niveaux/quotas/prix des packs Consommateur, Personnel et Marchand ; validation des identité/horaire/boîtes e-mail professionnelles ; décision sur les frais Marchand ; moment approprié du reset. Ne pas publier automatiquement de packs archivés, activer des comptes inconnus ou supprimer quatre membres sans assurance sur les données récemment saisies.

### Commandes utiles
- `php artisan cect:demo-check --json` : lecture seule, signale les familles de packs, les profils de sécurité et les rôles manquants ;
- `php artisan ledger:verify` et `php artisan audit:verify` : intégrité ;
- `APP_ENV=testing php artisan test --compact --no-ansi` : batterie fonctionnelle complète isolée par `phpunit.xml` sur `cectapi_test` ;
- `node scripts/test-superadmin-key-crypto.cjs` dans `cect-admin` : chiffrement/crypto du fichier ;
- `cect:reset-demo-activity` : **ne pas exécuter** avant une sauvegarde récente, une coupure du trafic et validation du catalogue à présenter. La commande de suppression demeure strictement protégée ; la visite de diagnostic ne modifie aucune donnée.

### Non homologué à ce stade
Une suite automatisée verte n'atteste pas un transfert monétaire réel, une liquidation via YEM Foundation, un règlement avec un PSP externe ou la rentabilité intrinsèque des packages et cashback. Les simulations volumétriques en tables temporaires ne sont pas une preuve d'aptitude à la charge de millions de transactions HTTP en production.
