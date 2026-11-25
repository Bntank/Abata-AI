
#!/bin/sh
set -eu

cd /var/www/html

: "${PORT:=8080}"

echo "[ABATA] Starting application..."

case "$PORT" in
    ''|*[!0-9]*)
        echo 'PORT must be numeric' >&2
        exit 1
        ;;
esac

if [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ]; then
    echo 'PORT out of range' >&2
    exit 1
fi

echo "[ABATA] Configuring Apache port $PORT"

printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf

sed -i -E "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:$PORT>/" \
    /etc/apache2/sites-available/000-default.conf

mkdir -p \
    bootstrap/cache \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/cache/locks \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

echo "[ABATA] Configuring Apache MPM"

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

exec docker-php-entrypoint "$@"
