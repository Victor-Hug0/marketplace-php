#!/bin/sh
set -e

cd /var/www/html

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/public \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

if [ -f artisan ]; then
    php artisan package:discover --ansi || true
    php artisan storage:link --force || true

    if [ "${APP_ENV}" = "production" ] || [ "${CACHE_CONFIG:-false}" = "true" ]; then
        php artisan config:cache
        php artisan route:cache
    fi
fi

exec "$@"
