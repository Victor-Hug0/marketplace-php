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

if [ -f composer.json ]; then
    if [ "${APP_ENV}" = "production" ]; then
        composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --optimize-autoloader
    else
        composer install --no-interaction --prefer-dist --no-progress --no-scripts
    fi
fi

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

if [ -f artisan ]; then
    rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
    php artisan package:discover --ansi || true
    php artisan storage:link --force || true
fi

exec apache2-foreground
