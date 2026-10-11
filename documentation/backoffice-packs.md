# Back-office et gestion des packs

## Deux interfaces administratives existent

1. Frontend React : `/admin/*`
2. Back-office Blade historique Laravel : `/backoffice/*`

Le frontend React est l'interface de gestion moderne actuellement enrichie. Le back-office Blade reste disponible pour ses workflows historiques.

## Incident d'affichage du back-office Blade

Symptôme observé le 11 octobre 2026 : page `/backoffice/login` rendue en HTML brut sans Bootstrap ni styles CECT.

Cause :

- les liens CSS/JS pointaient vers `/vendor/spark/*` et `/admin-assets/*` ;
- Nginx utilisait le root du frontend ;
- les requêtes d'assets retombaient sur le SPA `index.html` avec `Content-Type: text/html`.

Correction :

- location Nginx `/vendor/spark/` -> `/var/www/cect-api/public`
- location Nginx `/admin-assets/` -> `/var/www/cect-api/public`

## Connexion back-office

Un compte membre ordinaire n'a pas accès au back-office. Le rejet « Ce compte n'a plus accès au back-office » est attendu si l'identifiant n'est pas un compte staff actif.

Pour la recette, utiliser le compte staff/superadmin défini dans le README et dans la configuration de démo. Les mots de passe ne doivent pas être copiés dans cette documentation.

## Gestion des packs React

Le 11 octobre 2026, une vraie page de gestion du catalogue a été ajoutée au frontend :

`/admin/packs`

Fonctions :

- liste et filtres par famille/statut ;
- consultation d'un plan et de toutes ses versions ;
- création d'un plan ;
- création d'une version ;
- publication d'une version ;
- changement de statut du plan ;
- duplication ;
- suppression d'une version non publiée ;
- affichage de la version courante ;
- gestion des prix, quotas, périodes, cumul, limites, remises et taux de référence.

La navigation admin affiche maintenant « Packs » pour les utilisateurs possédant `packs.view`.

Les mutations sont protégées par `packs.manage` et par un step-up récent côté API.

## Backend catalogue

Routes API :

```text
GET    /api/v1/admin/packs/plans
POST   /api/v1/admin/packs/plans
GET    /api/v1/admin/packs/plans/{plan}
POST   /api/v1/admin/packs/plans/{plan}/versions
POST   /api/v1/admin/packs/plans/{plan}/versions/{version}/publish
DELETE /api/v1/admin/packs/plans/{plan}/versions/{version}
POST   /api/v1/admin/packs/plans/{plan}/transition
POST   /api/v1/admin/packs/plans/{plan}/duplicate
```

Test de non-régression exécuté : `CatalogAdministrationTest` — 40 tests, 200 assertions, succès complet.

## État de la grille de recette

La base contient actuellement les familles historiques Vendeur, Consommateur, Personnel et Marchand, avec certaines formules publiées et les autres encore en brouillon. La publication doit rester une action explicite.
