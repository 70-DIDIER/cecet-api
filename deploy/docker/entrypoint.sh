#!/bin/sh
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# Migrate before caching the configuration: DB_MIGRATION_URL (Neon direct,
# non-pooled endpoint) is only read here and must not end up in the cache.
if [ "$RUN_MIGRATIONS" = "true" ]; then
    DB_URL="${DB_MIGRATION_URL:-$DB_URL}" php artisan migrate --force --no-interaction
fi

# Opt-in demo accounts (DemoClientSeeder, refuses APP_ENV=production). Idempotent: an existing
# demo client is left untouched. Requires a private DEMO_PASSWORD (the code default is public).
if [ "$RUN_DEMO_SEED" = "true" ]; then
    if [ -z "$DEMO_PASSWORD" ]; then
        echo "RUN_DEMO_SEED=true requires DEMO_PASSWORD." >&2
        exit 1
    fi
    DB_URL="${DB_MIGRATION_URL:-$DB_URL}" php artisan db:seed --class=DemoClientSeeder --force --no-interaction
fi

php artisan storage:link --force >/dev/null 2>&1 || true
php artisan optimize

chown -R www-data:www-data storage bootstrap/cache

envsubst '${PORT}' < /etc/nginx/templates/cect.conf.template > /etc/nginx/conf.d/cect.conf

exec /usr/bin/supervisord -n -c /etc/supervisor/cect-supervisord.conf
