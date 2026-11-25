# syntax=docker/dockerfile:1

FROM composer:2 AS composer_bin
FROM php:8.4-apache-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PYTHON_BIN=/opt/abata-venv/bin/python \
    PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    OMP_NUM_THREADS=1 \
    OPENBLAS_NUM_THREADS=1 \
    MKL_NUM_THREADS=1 \
    NUMEXPR_NUM_THREADS=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-venv libgomp1 libonig-dev libxml2-dev unzip \
    && docker-php-ext-install -j2 pdo_mysql \
    && (php -m | grep -qi '^mbstring$' || docker-php-ext-install -j2 mbstring) \
    && (php -m | grep -qi '^dom$' || docker-php-ext-install -j2 dom) \
    && (php -m | grep -qi '^Zend OPcache$' || docker-php-ext-install -j2 opcache) \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer_bin /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY requirements.txt ./

RUN python3 -m venv /opt/abata-venv \
    && /opt/abata-venv/bin/pip install --no-cache-dir --only-binary=:all: -r requirements.txt \
    && /opt/abata-venv/bin/pip check

COPY composer.json composer.lock ./

RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader \
    && composer check-platform-reqs --no-dev

COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes
COPY resources ./resources
COPY public ./public
COPY python/abata_ml.py python/inventory_backtest.py ./python/
COPY datasets ./datasets
COPY ml_models ./ml_models
COPY tests/python ./tests/python
COPY artisan ./artisan

RUN mkdir -p bootstrap/cache \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/cache/locks \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    && composer dump-autoload --no-dev --optimize --no-scripts \
    && /opt/abata-venv/bin/python -m unittest discover -s tests/python -v \
    && rm -rf tests \
    && chown -R www-data:www-data storage bootstrap/cache

COPY deploy/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/apache-mpm.conf /etc/apache2/mods-available/mpm_prefork.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/abata.ini

RUN a2dismod mpm_event mpm_worker \
    && a2enmod mpm_prefork rewrite headers \
    && apache2ctl configtest

COPY <<'ABATA_ENTRYPOINT' /usr/local/bin/abata-entrypoint
#!/bin/sh
set -eu

cd /var/www/html

: "${PORT:=8080}"

echo "[ABATA] Starting application"

case "$PORT" in
    ''|*[!0-9]*)
        echo "PORT must be numeric" >&2
        exit 1
        ;;
esac

if [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ]; then
    echo "PORT out of range" >&2
    exit 1
fi

echo "[ABATA] Configuring Apache port $PORT"

printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf

sed -i -E "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:$PORT>/" /etc/apache2/sites-available/000-default.conf

mkdir -p \
    bootstrap/cache \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/cache/locks \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

a2dismod mpm_event mpm_worker
a2enmod mpm_prefork

apache2ctl configtest

echo "[ABATA] Discovering Laravel packages"

php artisan package:discover --ansi

echo "[ABATA] Initializing database"

php artisan abata:railway-init

echo "[ABATA] Preparing views"

php artisan view:cache

chown -R www-data:www-data storage bootstrap/cache

apache2ctl configtest

echo "[ABATA] Starting Apache on port $PORT"

exec apache2-foreground
ABATA_ENTRYPOINT

RUN chmod 755 /usr/local/bin/abata-entrypoint \
    && /bin/sh -n /usr/local/bin/abata-entrypoint \
    && echo "ABATA startup script validation successful"

EXPOSE 8080

ENTRYPOINT ["/bin/sh", "/usr/local/bin/abata-entrypoint"]
