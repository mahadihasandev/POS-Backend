#!/bin/sh
set -e

echo "==> [Drive App] Booting Laravel on Render..."

# Cache Laravel optimizations
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Execute database migrations on deploy
echo "==> [Drive App] Verifying database schema..."
php artisan migrate --force || true

echo "==> [Drive App] Ready to serve requests!"
