# CECT — Recette complète des profils et scénarios (20 octobre 2026)

**Qualification : préproduction / tests isolés. Dernière régression : 618/618 tests, 359 211 assertions. Statut des prestataires réels : NON HOMOLOGUÉ.**

## 1. Séparation des environnements et sauvegarde

- Tests métier PHPUnit/Pest : base PostgreSQL dédiée `cectapi_test` (définie de force par `phpunit.xml`). Les créations d'acteurs, souscriptions, règlements simulés et modifications de politique sont annulés/réinitialisés dans cette base après chaque scénario.
- Site de présentation : `cectapi_live`, conservé sans importation des comptes de test ni remise à zéro. Contrôle avant/après : **4 membres, 2 comptes internes, 6 souscriptions, 10 tentatives de paiement**.
- Nouvelle sauvegarde privée : `/home/ubuntu/cect-qa-backups/cect-live-before-persona-recette-20261020.dump` (247 658 octets, SHA-256 `1cf61e2790c2025e6770d9deca0fcb6392669be7d52344e162ed11278e28e60f`), archive lisible par `pg_restore --list`. Ne pas publier cette sauvegarde ni les paramètres secrets.

## 2. Tous les acteurs et statuts testés

| Famille / rôle | Nombre ou couverture | Opérations exercées |
|---|---|---|
| SuperAdmin | 1 identité nominative de test | Accès aux paramètres critiques, règles financières et restrictions des autres rôles |
| Administrateur | 1 identité de test | Consultation/gestion des membres, impossibilité de prendre les permissions réservées |
| Comptabilité | 1 identité de test | File de validation YEM, confirmation d'un rééchange avec step-up, création du paiement dû |
| Personne physique — Vendeur | 1 parcours financier + population synthétique | Souscription Vendeur, quota de cession isolé du solde de consommation |
| Personne physique — Consommateur | 1 parcours financier + population synthétique | Pack Consommateur, réservation du paiement QR, déclaration/confirmation |
| Personne physique — Personnel | 1 parcours financier + population synthétique | Pack Personnel rechargeable, 6 500 FCFA → 10 YEM + 0,5 YEM de bonus |
| Personne morale — Marchand | 1 parcours financier + population synthétique | Pack réservé aux PM, QR boutique, paiement reçu, échange YEM→FCFA |
| Membres de charge | **800** comptes factices dont **400 PF + 400 PM** | Création et isolement dans six statuts : actif, e-mail à vérifier, paiement en attente, activation en attente, suspendu, clôturé |
| Inscriptions via l'API publique | **12** (6 PF, 6 PM) | Formulaires HTTP réels de test, e-mails/Pernum uniques, statut e-mail en attente ; e-mails interceptés par Mail::fake |

**Les 800 et 12 comptes ne sont pas dans la base de démonstration.** Il s'agit d'utilisateurs fictifs créés par PHPUnit sur PostgreSQL `cectapi_test`.

## 3. Parcours métier intégral validé dans l'API de test

1. Création et autorisations des sept identités citées, plus états d'adhésion refusés.
2. Publication d'une version de chacune des **quatre familles de packs** : Vendeur, Consommateur, Personnel, Marchand (sur la base isolée uniquement).
3. Souscription et activation après **confirmation factice du paiement**.
4. Rechargement Personnel : achat à 650 FCFA/YEM, **6 500 FCFA → 10 YEM achetés + 0,500000 YEM bonus à 5 %**, aucune remise en FCFA.
5. Le Consommateur scanne le QR Marchand, réserve 40 YEM, déclare le transfert ; la déclaration seule **ne crée aucun crédit Marchand**.
6. Le Marchand confirme la réception et obtient 40 YEM admissibles au rééchange.
7. Le Marchand demande le rachat de 30 YEM ; Comptabilité confirme **25 YEM réellement reçus**, créant un règlement de **15 250 FCFA** au cours de 610 FCFA/YEM.
8. Vérification des rôles interdits, comptes non adhérents, séparation du solde Vendeur, intégrité du ledger et du journal d'audit.

## 4. Campagnes mesurées

| Campagne | Résultat | Trace persistante sur le serveur |
|---|---|---|
| Batterie backend complète, avec nouvelle matrice de profils | **607 tests passés, 359 198 assertions, 0 échec** | `/home/ubuntu/cect-qa-results/persona-all-final-20261020.log` |
| Nouvelle matrice des utilisateurs | **3 tests passés, 88 assertions** | `persona-matrix-e2e-v3.log` |
| Test de concurrence multi-processus PostgreSQL, rejoué seul | **5 tests passés, 84 assertions** | `persona-concurrency-20261020.log` |
| Stress SQL sur tables temporaires | **100 000 comptes, 1 000 000 opérations, 500 000 bonus** | `persona-stress-million-20261020.json` |
| Interface Admin | TypeScript/Vite compilés ; ESLint 9 fonctionnel, **0 erreur et 10 avertissements** | `persona-ui-admin-build.log`, `persona-lint-admin-fixed.log` |
| Interface Membre | TypeScript/Vite compilés ; ESLint 9 fonctionnel, **0 erreur et 10 avertissements** | `persona-ui-member-build.log`, `persona-lint-member-fixed.log` |
| Tests individuels sécurité d'état (après correction) | **11 déclinaisons de statuts + 3 parcours personas : 14 tests passés, 101 assertions** | `persona-status-patch-final.log` |
| Régression globale après modification du middleware d'authentification | **618 tests passés, 359 211 assertions, 0 échec** | `persona-regression-after-security-20261020.log` |

### Défauts et corrections de cette session

1. **Sécurité d'un compte désactivé :** un jeton déjà obtenu pouvait encore passer dans un contexte de session réutilisant un modèle utilisateur périmé. Le middleware global `EnsureStaffGuardianSession` vérifie désormais à chaque requête le **statut relu depuis la base**, même si le mode OTP du personnel n'est pas encore activé. Seuls les statuts Autorisés (membres actifs et en attente de paiement pour terminer leur adhésion ; agents internes actifs seulement) passent. Scénarios de transition actif → suspendu → clôturé rejoués sur le même jeton.
2. **Outillage frontend :** `npm run lint` des deux frontends échouait avant exécution faute de configuration ESLint 9. Le fichier `eslint.config.mjs` a été créé dans chaque dépôt sur la branche `custum`. Résultat : aucune erreur lint bloquante ; 10 avertissements non bloquants par application, à traiter lors de la prochaine optimisation.
3. **Anti-bot volontaire :** les 12 inscriptions depuis une même adresse IP ont rencontré le throttling HTTP 429. Le test de population simule désormais des adresses clientes distinctes, sans désactiver ni augmenter les limites de production.
4. **Isolation des tests :** ne jamais lancer deux suites PHP destructives sur `cectapi_test` simultanément. La première campagne avait été perturbée par ce chevauchement. Relance séquentielle réussie : **607/607**. Les tests de concurrence sont exécutés à part après la batterie complète.

## 5. Intégrité et contrôles externes

- API, portail Admin, portail membre, page des packs et configuration : HTTP **200**.
- Accès SuperAdmin sans certificat client : HTTP **403**.
- Staging : `ledger:verify` cohérent (**6 comptes de quota et 1 compte Marchand**) ; `audit:verify` cohérent (**132 événements**).
- Prestataires d'encaissement et de reversement toujours **fake** en staging ; **secret de webhook FedaPay absent** et identité sandbox réelle non validée par une transaction homologuée.
- Aucune confirmation de transfert **Foundation YEM réel** ; seul le protocole métier interne déclaration → validation est testé. Un clic « envoyé » n'est pas une preuve externe.
- `ops:golive` échoue **à juste titre** : environnement staging, paiements fictifs, pas d'alerte ops définie, manque de Comptable actif, absence de signature webhook FedaPay.
- `cect:demo-check` échoue **à juste titre** : un seul pack Vendeur publié, **zéro Consommateur / Personnel / Marchand publié**, aucun Administrateur ou Comptable actif, nouveau mode OTP personnel non activé.

## 6. Décisions et limites de la recette

La plateforme est **validée pour ses scénarios automatisés dans la base isolée**, pas pour une exploitation financière réelle ni une démonstration en conditions de production complète. N'inventez pas de disponibilité de l'API Foundation ou de paiements effectifs FedaPay.

Pour préparer une vraie démonstration complète, le SuperAdmin doit **valider et publier des versions** des trois familles de packs manquantes et préparer des collaborateurs internes actifs. Les frais Marchands et le cashback sont paramétrables et versionnés, mais leur rentabilité dépend des frais PSP, du stock et de la trésorerie.

Aucun nettoyage destructif n'a été réalisé sur `cectapi_live`. La réinitialisation de présentation doit être décidée séparément, avec sauvegarde et vérification des droits SuperAdmin.
