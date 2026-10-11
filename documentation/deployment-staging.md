# Déploiement et environnement de recette

## Serveur courant

- OS : Ubuntu 26.04
- Nginx : reverse proxy / serveur statique
- PHP-FPM : 8.5
- PostgreSQL : 18
- Node.js : 22
- Queue : service `cect-queue`
- Scheduler : service `cect-scheduler`
- HTTPS : Let's Encrypt sur `13-50-26-235.sslip.io`

## URL

`https://13-50-26-235.sslip.io`

## Environnement

La plateforme courante est une **recette/staging**, pas la production financière.

Paramètres structurants :

```text
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://13-50-26-235.sslip.io
SANCTUM_STATEFUL_DOMAINS=13-50-26-235.sslip.io
CORS_ALLOWED_ORIGINS=https://13-50-26-235.sslip.io
SESSION_SECURE_COOKIE=true
```

Les valeurs secrètes ne sont jamais versionnées dans Git.

## Répertoires

```text
/var/www/cect-api
/var/www/cect-frontend
/home/ubuntu/cect-config-backups
```

## Nginx

Le `root` principal est le build frontend.

Les chemins Laravel dédiés sont routés vers PHP-FPM. Les assets du back-office Blade doivent être routés explicitement vers le répertoire public du backend.

Le 11 octobre 2026, un défaut de configuration a été corrigé : les URLs `/vendor/spark/*` et `/admin-assets/*` retombaient sur `index.html` du frontend et étaient renvoyées avec le type `text/html`. Résultat : la page `/backoffice/login` apparaissait sans style. Deux locations Nginx dédiées servent désormais ces répertoires depuis `/var/www/cect-api/public`.

Avant tout reload Nginx :

```bash
nginx -t
```

Puis seulement après validation, recharger le service.

## Déploiement Git

Le serveur de recette suit uniquement les branches `joseph`.

Ne jamais déployer directement `main` sans décision explicite.

Après mise à jour backend :

```bash
git fetch origin joseph
git reset --hard origin/joseph
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan queue:restart
```

Après mise à jour frontend :

```bash
git fetch origin joseph
git reset --hard origin/joseph
npm run build
```

Toujours vérifier `/api/v1/health` après déploiement.
