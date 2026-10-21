# CECT — Audit comparatif des branches main et custum

Date : 21 octobre 2026. Périmètre : uniquement les dépôts GitHub `70-DIDIER/cect-admin`, `70-DIDIER/CECT-frontend` et `70-DIDIER/cecet-api`.

## Méthode

- Vérification des branches sur GitHub, comparaison `main...custum` et revue des diffs de code (routes, menu, fichiers React et actions Laravel).
- Lors du contrôle initial, `main` était l'ancêtre de `custum` pour les trois dépôts : admin +81 commits, frontend +32, API +197. Cela ne garantit **pas** la parité fonctionnelle.
- Les fonctionnalités peuvent devenir inaccessibles par changement de route/menu ou de logique métier sans suppression physique des fichiers.
- Audit statique : il ne remplace pas compilation, tests end-to-end ou recette sur le serveur.

## Écart confirmé et correction effectuée

**Administration — gestion des pays de réception :** dans `main`, l'écran « Cashback & pays » rendait une carte dédiée de gestion des pays (`payout.countries`). Dans `custum`, le composant `CountriesPage` subsistait comme export par défaut de `src/pages/admin/CashbackPage.tsx`, mais la route `/admin/cashback` redirigeait vers les packs et `/admin/pays` vers la configuration JSON. Aucun menu dédié ne permettait d'accéder à la carte des pays.

Correction sur `70-DIDIER/cect-admin@custum` :
1. Commit `6ac85758eb056e237766797ff2efb4d224402abc` : restauration de `/admin/pays` vers `CountriesPage`.
2. Commit `7c8f8bbf3c74999a9ec572f7668b63ca611d3789` : ajout de « Pays de réception » au menu, permission `settings.view`.

La définition `payout.countries` et les routes API de lecture/modification des paramètres existent déjà. Aucune migration ni donnée financière n'a été modifiée.

## Évolutions volontaires à ne pas annuler en copiant main

- `main` admin exposait la règle de cashback globale et quatre familles. `custum` place le cashback dans le paramétrage/versionnage des packs Consommateur et Personnel ; Vendeur et Marchand sont exclus selon le cadrage actuel. Ne pas rétablir aveuglément les anciennes règles.
- L'étape de choix du moyen de paiement a été retirée du formulaire d'inscription frontend ; le paiement intervient après vérification d'e-mail selon les décisions métier du 20 octobre.
- Le rôle interne `validateur` a été retiré et ses responsabilités regroupées dans Comptabilité ; ne pas restaurer ses permissions ou accès sans nouvelle décision.
- `custum` ajoute la sécurisation SuperAdmin (mTLS, clés, OTP), un catalogue de paiement africain, des simulations explicites, et des fonctionnalités de gestion des packs.

## Incohérence backend restant à investiguer avant déploiement

Dans `cecet-api@custum`, la définition modifiable `packs.families_excluded_from_limit` demeure dans `SettingsCatalog.php`, mais `SubscribeToPack::assertWithinStructuredLimit()` ne lit plus ce paramètre ; seule la famille VENDEUR est soumise au nombre maximal `packs.max_structured_active`. Dans `main`, la liste des exclusions était effectivement prise en compte.

C'est un **écart entre interface de configuration et logique exécutée**, pas une preuve que la logique métier de `main` doit être restaurée. Une décision métier sur la limite par famille est nécessaire avant toute correction de ce comportement financier/contractuel.

## Validation et déploiement

- Les derniers GitHub Actions `custum` (API et admin) consultés étaient en échec. Les étapes/logs détaillés n'ont pas pu être récupérés par la connexion.
- Le workflow du frontend ne surveillait pas `custum` lors de cet audit.
- Les agents Desktop Commander du serveur étaient hors ligne, donc aucun déploiement distant ni test runtime n'a pu être effectué.
- Avant déploiement : compiler les fronts, exécuter tests API / sécurité et parcours inscription, packs, paiement, ventes, pays de réception, contrôler migrations et sauvegardes, puis vérifier URL/health et révocation des anciennes versions.

**Statut :** correction de navigation poussée sur GitHub ; déploiement et recette non réalisés.
