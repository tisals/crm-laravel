#!/bin/sh
set -e

cd /var/www/html

# Si no hay .env, crearlo
if [ ! -f .env ]; then
    cp .env.example .env 2>/dev/null || true
fi

# Generar APP_KEY si no está seteada
php artisan key:generate --force 2>/dev/null || true

# Migrar si no se ha hecho
if [ ! -f storage/framework/.migrated ]; then
    php artisan migrate --force 2>/dev/null || true
    touch storage/framework/.migrated
fi

# Descubrir paquetes (reemplaza post-autoload-dump)
php artisan package:discover --ansi 2>/dev/null || true

# PHP-FPM en background
php-fpm -D

# Nginx en foreground (PID 1)
exec nginx -g "daemon off;"
