#!/bin/sh
set -e

echo "Starting Roll4It..."

mkdir -p /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

echo "Waiting for Postgres on db:5432..."
count=0
until nc -z db 5432 2>/dev/null; do
    count=$((count + 1))
    if [ $count -ge 30 ]; then
        echo "Postgres not reachable after 30 attempts"
        exit 1
    fi
    sleep 2
done
sleep 2

count=0
until php -r "new PDO('pgsql:host=db;port=5432;dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    count=$((count + 1))
    if [ $count -ge 10 ]; then
        echo "Postgres authentication still failing; continuing so migrations show the real error"
        break
    fi
    sleep 2
done

if [ -z "$ROLL_EMAIL_PEPPER" ] || [ -z "$SHOPIFY_API_KEY" ] || [ -z "$SHOPIFY_API_SECRET" ]; then
    echo "WARNING: SHOPIFY_API_KEY, SHOPIFY_API_SECRET and ROLL_EMAIL_PEPPER must all be set."
fi

# Only the web container migrates; queue and scheduler skip the entrypoint.
php artisan migrate --force

# Env comes from docker at runtime, so never cache config.
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear || true

chown -R www-data:www-data /var/www/html/storage

echo "Roll4It is ready."

if [ "$#" -gt 0 ]; then
    exec "$@"
else
    exec /usr/bin/supervisord -c /etc/supervisord.conf
fi
