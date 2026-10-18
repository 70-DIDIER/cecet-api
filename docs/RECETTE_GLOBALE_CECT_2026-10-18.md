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
