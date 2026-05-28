# ========================================================
# ETAPA 1: Dependencias Composer
# ========================================================
FROM composer:2 AS vendor
WORKDIR /app

# 1. Instalar dependencias (sin scripts, sin autoloader)
#    Esta capa se cachea si composer.lock no cambia
COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --prefer-dist \
    --no-autoloader \
    --no-scripts

# 2. Copiar el código de la aplicación
COPY . .

# 3. Regenerar autoloader (sin scripts — se corren en entrypoint)
RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

# ========================================================
# ETAPA 2: Runtime (PHP-FPM + Nginx)
# ========================================================
FROM php:8.2-fpm-alpine

# Nginx
RUN apk add --no-cache nginx

# Extensiones PHP (pre-compiladas, no desde fuente)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql pdo_sqlite bcmath gd >/dev/null 2>&1

# Copiar vendor + código
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY . /var/www/html
COPY nginx.conf /etc/nginx/http.d/default.conf

# Permisos
RUN mkdir -p /var/www/html/storage/framework/{sessions,views,cache} \
             /var/www/html/bootstrap/cache && \
    chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache

# Entrypoint
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
