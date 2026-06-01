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
# Usa DB_ROOT_USER/DB_ROOT_PASSWORD si están definidas, sino DB_USERNAME/DB_PASSWORD
if [ -n "$DB_HOST" ] && [ -n "$DB_DATABASE" ]; then
    DB_CREATE_USER="${DB_ROOT_USER:-$DB_USERNAME}"
    DB_CREATE_PASS="${DB_ROOT_PASSWORD:-$DB_PASSWORD}"
    if [ -n "$DB_CREATE_USER" ] && [ -n "$DB_CREATE_PASS" ]; then
        echo "-> Checking database '${DB_DATABASE}' (as ${DB_CREATE_USER})..." >&2
        cat > /tmp/init-db.php << 'PHPEOF'
<?php
$host = getenv('DB_HOST');
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_CREATE_USER');
$pass = getenv('DB_CREATE_PASS');
$db   = getenv('DB_DATABASE');

try {
    $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db'");
    if (!$stmt->fetch()) {
        $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "Database '$db' created.\n";
        // Grant all privileges to the app user if we're root
        $appUser = getenv('DB_USERNAME');
        $appPass = getenv('DB_PASSWORD');
        if ($appUser && $appPass && $user !== $appUser) {
            $pdo->exec("GRANT ALL PRIVILEGES ON `$db`.* TO '$appUser'@'%' IDENTIFIED BY '$appPass'");
            $pdo->exec("FLUSH PRIVILEGES");
            echo "Privileges granted to '$appUser'.\n";
        }
    } else {
        echo "Database '$db' already exists.\n";
    }
} catch (Exception $e) {
    echo "DB_CREATE_FAILED:" . $e->getMessage() . "\n";
}
PHPEOF
        DB_CREATE_USER="$DB_CREATE_USER" DB_CREATE_PASS="$DB_CREATE_PASS" \
            php /tmp/init-db.php 2>&1 | while IFS= read -r line; do
            case "$line" in
                DB_CREATE_FAILED:*) echo "-> WARNING: Cannot create database ($(echo "$line" | sed 's/DB_CREATE_FAILED://'))" >&2 ;;
                *) echo "-> $line" >&2 ;;
            esac
        done
        rm -f /tmp/init-db.php
    fi
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
