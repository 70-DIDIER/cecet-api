# Architecture de la plateforme

## Vue d'ensemble

La plateforme est composée de deux applications déployées derrière le même Nginx et la même origine HTTPS.

```text
Navigateur
   |
   | HTTPS
   v
Nginx
   +--> Frontend React/Vite
   |    /var/www/cect-frontend/dist
   |
   +--> Laravel API / back-office
        /var/www/cect-api/public/index.php
        PHP-FPM 8.5
             |
             +--> PostgreSQL
             +--> Queue database
             +--> Scheduler
             +--> FedaPay
             +--> SMTP Gmail
```

## Routage principal

- `/`, `/connexion`, `/espace/*`, `/admin/*` : frontend React.
- `/api/*` : Laravel.
- `/sanctum/csrf-cookie` : Laravel.
- `/backoffice/*` : back-office Blade historique Laravel.
- `/admin/login`, assets Filament : Laravel / Filament.
- `/vendor/spark/*` et `/admin-assets/*` : assets statiques du back-office Blade servis depuis `cect-api/public`.

## Identité et rôles

Rôles métier retenus :

- Utilisateur / membre
- Administrateur
- Superadmin
- Validateur
- Comptabilité
- Yempay est externe et n'est pas un rôle applicatif humain.

Le backend impose les permissions. Le frontend masque uniquement les fonctions que l'utilisateur ne peut pas utiliser ; il ne remplace jamais les contrôles d'autorisation serveur.

## Packs

Familles actuellement supportées :

- `VENDEUR`
- `CONSOMMATEUR`
- `PERSONNEL`
- `MARCHAND`

Les plans de packs possèdent un cycle de vie : `draft -> published -> suspended/retired -> archived`.

Les conditions sont stockées dans des versions. Une version publiée devient immuable. Toute modification de prix, quota, durée ou règle se fait en créant une nouvelle version puis en la publiant.

## Principe de séparation

Le frontend n'effectue jamais de décision financière ou d'activation définitive. Les décisions sont prises par le backend après validation de l'état métier et, pour les paiements réels, après réconciliation avec le prestataire.
