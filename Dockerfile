# syntax=docker/dockerfile:1

# CECT API image for Render (single web service):
# nginx + PHP-FPM + queue worker + scheduler, supervised in one container.

# ---- Frontend assets (welcome page uses @vite) ----
FROM node:22.20.0-bookworm-slim AS assets
WORKDIR /app
COPY package.json vite.config.js ./
# npm 10 crashes resolving Vite 8 peer dependencies ("reading 'edgesOut'"); npm 11 does not.
RUN npm install -g npm@11.6.2 \
    && npm install --no-audit --no-fund --ignore-scripts
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- Runtime ----
FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx supervisor gettext-base unzip git \
        libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath intl gd zip pcntl opcache \
    && apt-get purge -y --auto-remove libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && apt-get install -y --no-install-recommends libpq5 libicu72 libzip4 libpng16-16 libjpeg62-turbo libfreetype6 \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

COPY --from=composer:2.8.4 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first for layer caching; scripts need the application code.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY . .
COPY --from=assets /app/public/build ./public/build

# Runs package:discover and filament:upgrade (publishes Filament assets).
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/zz-cect.ini
COPY deploy/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-cect.conf
COPY deploy/docker/nginx.conf.template /etc/nginx/templates/cect.conf.template
COPY deploy/docker/supervisord.conf /etc/supervisor/cect-supervisord.conf
COPY deploy/docker/entrypoint.sh /usr/local/bin/cect-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/cect-entrypoint && chmod +x /usr/local/bin/cect-entrypoint

ENV PORT=10000 \
    LOG_CHANNEL=stderr \
    RUN_MIGRATIONS=true \
    RUN_QUEUE_WORKER=true \
    RUN_SCHEDULER=true

EXPOSE 10000

ENTRYPOINT ["cect-entrypoint"]
