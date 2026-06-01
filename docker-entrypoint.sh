#!/bin/sh
set -e

cd /var/www/html

# Asegurar permisos de logs (PHP-FPM corre como www-data)
chown -R www-data:www-data storage/logs storage/framework bootstrap/cache 2>/dev/null || true

# Limpiar flag stale: si existe .migrated pero la DB no tiene tablas, resetear
if [ -f storage/framework/.migrated ]; then
    TABLE_COUNT=$(php -r "
        try {
            \$pdo = new PDO('mysql:host=${DB_HOST};port=${DB_PORT:-3306};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');
            \$stmt = \$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \"${DB_DATABASE}\"');
            echo \$stmt->fetchColumn();
        } catch (Exception \$e) { echo '0'; }
    " 2>/dev/null)
    if [ "$TABLE_COUNT" = "0" ]; then
        rm -f storage/framework/.migrated storage/framework/.seeded
        echo "-> Stale flags removed (DB was empty or missing)" >&2
    fi
fi

# Si no hay .env, crearlo
if [ ! -f .env ]; then
    cp .env.example .env 2>/dev/null || true
fi

# Generar APP_KEY siempre (fuerza sobre cualquier valor inválido)
php artisan key:generate --force 2>&1 || true

# Crear base de datos si no existe (mysql/mariadb)
if [ -n "$DB_HOST" ] && [ -n "$DB_DATABASE" ] && [ -n "$DB_USERNAME" ] && [ -n "$DB_PASSWORD" ]; then
    echo "-> Checking database '${DB_DATABASE}'..." >&2
    php -r "
        \$pdo = new PDO('mysql:host=${DB_HOST};port=${DB_PORT:-3306}', '${DB_USERNAME}', '${DB_PASSWORD}', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        \$stmt = \$pdo->query('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = \"${DB_DATABASE}\"');
        if (!\$stmt->fetch()) {
            \$pdo->exec('CREATE DATABASE \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            echo \"-> Database '${DB_DATABASE}' created.\n\" >&2;
        } else {
            echo \"-> Database '${DB_DATABASE}' already exists.\n\" >&2;
        }
    " 2>&1 || echo "-> WARNING: Could not create database (may already exist or insufficient privileges)" >&2
fi

# Migrar si no se ha hecho exitosamente
if [ ! -f storage/framework/.migrated ]; then
    if php artisan migrate --force 2>&1; then
        echo "-> Migrations OK" >&2
        touch storage/framework/.migrated
    else
        echo "-> MIGRATION FAILED (check DB credentials, APP_KEY, and DB server)" >&2
    fi
fi

# Seeders (solo si migramos exitosamente)
if [ -f storage/framework/.migrated ] && [ ! -f storage/framework/.seeded ]; then
    if php artisan db:seed --force 2>&1; then
        echo "-> Seeders OK" >&2
        touch storage/framework/.seeded
    else
        echo "-> Seeder error (non-fatal)" >&2
        touch storage/framework/.seeded
    fi
fi

# Descubrir paquetes (reemplaza post-autoload-dump)
php artisan package:discover --ansi 2>/dev/null || true

# PHP-FPM en background
php-fpm -D

# Nginx en foreground (PID 1)
exec nginx -g "daemon off;"
