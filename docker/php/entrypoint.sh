#!/bin/sh
set -e

if [ "$1" = "php-fpm" ]; then
    rm -f storage/framework/.ready

    if [ ! -f .env ]; then
        cp .env.example .env
    fi

    if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
        composer install --no-interaction --prefer-dist
    fi

    if ! grep -q "^APP_KEY=.\+" .env; then
        php artisan key:generate --force
    fi

    php artisan migrate --force

    touch storage/framework/.ready
fi

exec "$@"
