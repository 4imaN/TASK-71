#!/bin/sh
# docker/scripts/docker-entrypoint.sh
#
# Entrypoint for app, queue-worker, and scheduler containers.
# Sources generated secrets from the runtime volume before starting the process.
# The runtime volume is populated by the bootstrap container before this runs.

set -e

RUNTIME_CONFIG="/runtime/app.env"

if [ ! -f "$RUNTIME_CONFIG" ]; then
    echo "ERROR: ${RUNTIME_CONFIG} not found."
    echo "       The bootstrap service must run before app containers start."
    echo "       Use: docker compose up --build"
    exit 1
fi

# Export secrets into the container environment.
# Static non-secret config is already set via docker-compose environment: blocks.
# shellcheck disable=SC1090
set -a
. "$RUNTIME_CONFIG"
set +a

# Trust the internal CA so that phpredis/OpenSSL verify the internal-network
# TLS certificates used by Redis and PostgreSQL.
INTERNAL_CA="/runtime/certs/ca.crt"
if [ -f "$INTERNAL_CA" ]; then
    cp "$INTERNAL_CA" /usr/local/share/ca-certificates/internal-ca.crt
    update-ca-certificates 2>/dev/null || true
fi

# Copy built public assets (CSS/JS from Vite, index.php, etc.) into the
# shared volume so nginx can serve static files directly.
SHARED_PUBLIC="/shared_public"
if [ -d "$SHARED_PUBLIC" ]; then
    cp -a /var/www/html/public/. "$SHARED_PUBLIC/"
fi

# Ensure storage directories exist (volume may be empty on first run)
mkdir -p /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

# Run migrations + seeders automatically on the main app container only
# (php-fpm). Queue-worker and scheduler skip this — they start with
# "php artisan queue:work" / "php artisan schedule:work" instead.
if [ "$1" = "php-fpm" ]; then
    echo "entrypoint: running migrations..."
    php artisan migrate --force 2>&1
    echo "entrypoint: running seeders..."
    php artisan db:seed --force 2>&1
    echo "entrypoint: database ready"
fi

exec "$@"
