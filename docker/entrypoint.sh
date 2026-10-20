#!/bin/sh
set -e

cd /var/www/html

# Development bind-mounts the project: give www-data the UID/GID of whoever owns it on the host,
# so files written by the container stay editable outside it. In the production image the
# project already belongs to www-data, and a root-owned mount (Docker Desktop) is left alone.
PROJECT_UID="$(stat -c %u .)"
PROJECT_GID="$(stat -c %g .)"
if [ "$(id -u)" = "0" ] && [ "${PROJECT_UID}" != "0" ] && [ "${PROJECT_UID}" != "$(id -u www-data)" ]; then
    sed -i "s/^www-data:x:[0-9]*:[0-9]*:/www-data:x:${PROJECT_UID}:${PROJECT_GID}:/" /etc/passwd
    sed -i "s/^www-data:x:[0-9]*:/www-data:x:${PROJECT_GID}:/" /etc/group
fi

# When www-data owns the project, run everything that writes into it as www-data, never as root.
RUN_AS_APP=0
if [ "$(id -u)" = "0" ] && [ "${PROJECT_UID}" = "$(id -u www-data)" ]; then
    RUN_AS_APP=1
fi

as_app() {
    if [ "${RUN_AS_APP}" = "1" ]; then
        setpriv --reuid=www-data --regid=www-data --init-groups -- "$@"
    else
        "$@"
    fi
}

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/public \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

if [ -f composer.json ]; then
    if [ "${APP_ENV}" = "production" ]; then
        as_app composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --optimize-autoloader
    else
        as_app composer install --no-interaction --prefer-dist --no-progress --no-scripts
    fi
fi

if [ -f artisan ]; then
    rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
    as_app php artisan package:discover --ansi || true
    as_app php artisan storage:link --force || true
fi

if [ "$#" -eq 0 ] || [ "$1" = "apache2-foreground" ]; then
    exec apache2-foreground
fi

if [ "${RUN_AS_APP}" = "1" ]; then
    exec setpriv --reuid=www-data --regid=www-data --init-groups -- "$@"
fi

exec "$@"
