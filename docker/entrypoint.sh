#!/bin/sh
set -e

# Cache config at runtime when env vars are available
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations if DB is reachable
php artisan migrate --force --no-interaction 2>/dev/null || echo "Migration skipped (DB not ready or no pending migrations)"

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisord.conf
