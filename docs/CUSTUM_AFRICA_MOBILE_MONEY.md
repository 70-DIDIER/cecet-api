# CECT — Mobile Money africain en mode simulation

**Branche** : `custum` exclusivement. Ne pas fusionner dans `main` sans recette et décision métier.

## Périmètre

- Les **54 pays souverains africains** sont reconnus par l'indicatif téléphonique international (E.164).
- Les **8 pays UEMOA** disposent d'opérateurs et de services nommément documentés dans `AfricanMobileMoneyCatalog`.
- **46 pays africains** possèdent des réseaux ou services nommément répertoriés ; **44 identifiants uniques** représentent 118 couples pays/opérateur.
- Les autres pays sont desservis **uniquement en simulation** par l'option `AFRICA_WALLET_TEST` (« Autre portefeuille mobile — simulation uniquement ») : elle **ne prétend pas** qu'une institution locale est opérationnelle ou partenaire.
- Tous les nouveaux moyens de paiement sont **désactivés en production**. Les intégrations réelles demandent des contrats, homologations, devises et systèmes de conversion, et ne font pas partie de ce changement.

## UEMOA — principaux moyens répertoriés

| Pays | Exemples référencés, tous simulés |
| --- | --- |
| Bénin (BJ) | MTN MoMo, Moov Money, Celtiis Cash, Coris Money, ID Money, BestCash |
| Burkina Faso (BF) | Orange Money, Moov Money, Wave, Wizall, Coris Money, Telecel |
| Côte d'Ivoire (CI) | Orange Money, MTN MoMo, Moov Money, Wave, Sama Money, Wizall, Celpaid, Pe Ya Pay |
| Guinée-Bissau (GW) | Orange Money, MTN MoMo |
| Mali (ML) | Orange Money, Moov Money, Sama Money, Wave, Wizall, Coris Money |
| Niger (NE) | Airtel Money, Orange Money, Flooz, Wave, Coris Money, Zamani Cash |
| Sénégal (SN) | Orange Money, Wave, Free Money, Wizall, QuickPay, TouchPoint |
| Togo (TG) | Mixx, Flooz, Gozem Money, Coris Money |

**Sources principales** :
- Banque Centrale des États de l'Afrique de l'Ouest, établissements de monnaie électronique, situation au 28 février 2026 : https://www.bceao.int/fr/content/etablissements-de-monnaie-electronique
- GSMA Mobile Money Deployment Tracker (mise à jour mensuelle) : https://www.gsma.com/mobile-money-metrics/
- GSMA *The State of the Industry Report on Mobile Money 2026*, annexe des participants 2025 : https://www.gsma.com/sotir/wp-content/plugins/plugin_gsma_sotir/reports/The-State-of-the-Industry-Report-2026_English.pdf

Les listes ne sont pas une garantie d'exhaustivité ou de disponibilité en temps réel. Un pays sans opérateur documenté n'affiche pas d'opérateur inventé.

## Déploiement staging

1. Sauvegarder PostgreSQL et vérifier `APP_ENV=staging`, le fournisseur `payment.inbound_provider=fake` et FedaPay sandbox.
2. Déployer **exclusivement** `70-DIDIER/cecet-api: custum` et vérifier `/api/v1/health`.
3. Appliquer les nouvelles versions des paramètres, en conservant l'historique et l'audit : `CECT_AFRICA_SIM_ROLLOUT=YES php scripts/apply-africa-simulation-staging.php`.
4. Déployer `70-DIDIER/CECT-frontend: custum` après compilation TypeScript/Vite.
5. Vérifier `GET /api/v1/config`, les réseaux par pays, un code de téléphone E.164 de chacun des 8 pays UEMOA et le simulateur sur un compte de recette.
6. Vérifier qu'aucune opération réelle n'a été transmise à FedaPay, que les comptes et paiements préexistants sont inchangés et que l'ancien frontend Didier reste disponible en rollback.

## Interface

Les logos existants Mixx, Flooz, Wave et Orange Money restent servis depuis les assets locaux du frontend. Les autres réseaux utilisent un **pictogramme neutre + abréviation** tant qu'un véritable logo autorisé n'a pas été obtenu. Aucun logo officiel n'est inventé.

La sélection et la validation des moyens de paiement sont contrôlées par l'API : l'interface ne fait pas autorité.
