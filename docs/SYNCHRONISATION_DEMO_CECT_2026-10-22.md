# CECT — Synchronisation de la démonstration (22 octobre 2026)

## Références sources

La source fonctionnelle et les correctifs sont suivis exclusivement dans les trois dépôts `70-DIDIER`, branche `custum` :

- `70-DIDIER/CECT-frontend` : parcours d'inscription PF/PM, connexion, vérification documentaire KYC (Sandbox), parcours d'adhésion, choix de moyen de paiement simulé, espace membre. Le fichier `public/cect-kyc-demo-link.js` est suivi dans Git ; `index.html` le charge explicitement afin de reproduire l'élément de démonstration de la page publique dans les builds.
- `70-DIDIER/cecet-api` : création de dossier, OTP, statuts, workflow KYC, paiement simulé, activation de l'adhésion, endpoints membres, contrôles métier et tests de régression.
- `70-DIDIER/cect-admin` : interfaces d'administration, sécurité Superadmin et traitement des vérifications KYC.

Les changements sont conservés sur la branche **custum** ; **ne pas les confondre avec la branche main**. Ne pas déployer un ancien build de `main` à la place de `custum`.

## Démonstration vérifiée côté utilisateur

L'utilisateur a confirmé dans l'interface de présentation que l'inscription, la vérification KYC et l'adhésion fonctionnent de bout en bout le 22 octobre 2026. Cette confirmation visuelle n'est pas une certification de l'ensemble des autres opérations (packs, vente/achat de YEM, comptabilité et marchands).

La démonstration utilise un **environnement de test / Sandbox** et des **paiements simulés**. Aucun débit réel ne doit être déclenché.

## Réinitialisation ciblée des comptes membres (22 octobre 2026)

Une purge contrôlée des membres a été exécutée sur `cectapi_live` en environnement `staging`, après sauvegarde PostgreSQL au format archive personnalisée et contrôle du manifeste `pg_restore`. Elle ciblait **quatre comptes `kind=member`** et leurs enregistrements associés : dossiers KYC (3), OTP, sessions, identités PERNUM, adhésion, organisation, notifications, intents/tentatives de paiement et clés d'idempotence propres aux membres.

La purge n'a pas supprimé les **deux comptes internes** (Superadmin et Comptabilité), les paramètres, rôles, permissions, audit, historiques de sécurité ou sauvegardes. Après l'opération, le contrôle indépendant a confirmé **0 membre** et **2 comptes internes**. De nouvelles inscriptions réalisées ensuite peuvent faire évoluer ces compteurs.

**Important :** la commande `cect:reset-demo-activity` est plus large que cette opération ciblée : elle efface aussi des tables métier globales. Elle ne doit pas être utilisée comme synonyme de « supprimer uniquement les utilisateurs ». Ne jamais lancer de purge sans sauvegarde, vérification des FK, examen du périmètre et accord explicite.

## Déploiements reproductibles

1. Développer et versionner les corrections dans les branches `custum` de ces trois dépôts.
2. Exécuter les tests et reconstruire le frontend depuis le code versionné (ne pas compter sur des modifications manuelles d'artefacts dans le répertoire de publication).
3. Confirmer les URL, migrations et réglages de simulation de paiement avant chaque démonstration.
4. Préserver les secrets (`.env`, sauvegardes, identifiants, captures KYC) **hors de Git**.
