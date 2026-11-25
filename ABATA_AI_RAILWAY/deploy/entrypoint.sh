
#!/bin/sh
set -eu

cd /var/www/html

: "${PORT:=8080}"

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

# Configure Apache listening port.
printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf

sed -i -E "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:$PORT>/" /etc/apache2/sites-available/000-default.conf

# Prepare Laravel directories.
mkdir -p \
    bootstrap/cache \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/cache/locks \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

# Fix multiple Apache MPM modules.
# PHP mod_php requires prefork.
a2dismod mpm_event mpm_worker
a2enmod mpm_prefork

# Validate Apache configuration.
apache2ctl configtest

# Initialize Laravel and database.
php artisan package:discover --ansi
php artisan abata:railway-init
php artisan view:cache

# Set directory permissions.
chown -R www-data:www-data storage bootstrap/cache

# Final Apache configuration check.
apache2ctl configtest

# Start application.
exec docker-php-entrypoint "$@"
