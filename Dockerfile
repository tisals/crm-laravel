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
RUN install-php-extensions pdo_mysql pdo_sqlite bcmath gd redis >/dev/null 2>&1

# Aumentar workers de PHP-FPM para soportar concurrencia del frontend
RUN echo "pm.max_children = 50" >> /usr/local/etc/php-fpm.d/www.conf \
    && echo "pm.start_servers = 10" >> /usr/local/etc/php-fpm.d/www.conf \
    && echo "pm.min_spare_servers = 5" >> /usr/local/etc/php-fpm.d/www.conf \
    && echo "pm.max_spare_servers = 20" >> /usr/local/etc/php-fpm.d/www.conf

# OPcache tuning: bind-mount en Docker sobre Windows es lento para stat() de
# ~7.375 archivos vendor. Deshabilitar validación de timestamps evita pagar ese
# costo en cada request. Es seguro en dev (los archivos no cambian entre reqs).
COPY docker/opcache-tune.ini /usr/local/etc/php/conf.d/zz-opcache-tune.ini

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
