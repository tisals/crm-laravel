#!/bin/sh
set -e

cd /var/www/html

# Asegurar permisos de logs (PHP-FPM corre como www-data)
chown -R www-data:www-data storage/logs storage/framework bootstrap/cache 2>/dev/null || true

# Si no hay .env, crearlo
if [ ! -f .env ]; then
    cp .env.example .env 2>/dev/null || true
fi

# Generar APP_KEY siempre (fuerza sobre cualquier valor inválido)
php artisan key:generate --force 2>&1 || true

# Migrar si no se ha hecho
if [ ! -f storage/framework/.migrated ]; then
    php artisan migrate --force 2>&1 || echo "Migration error (check APP_KEY and DB vars)"
    touch storage/framework/.migrated
fi

# Seeders (solo si migramos exitosamente)
if [ -f storage/framework/.migrated ] && [ ! -f storage/framework/.seeded ]; then
    php artisan db:seed --force 2>&1 || echo "Seed error (non-fatal, continuing)"
    touch storage/framework/.seeded
fi

# Descubrir paquetes (reemplaza post-autoload-dump)
php artisan package:discover --ansi 2>/dev/null || true

# PHP-FPM en background
php-fpm -D

# Nginx en foreground (PID 1)
exec nginx -g "daemon off;"
