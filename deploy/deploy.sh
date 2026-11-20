#!/usr/bin/env bash
# Update a running Achylstic server to the latest code on the main branch.
# Run it on the server, from the project folder:  bash deploy/deploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> Maintenance mode on"
php artisan down --retry=30 || true

echo "==> Pulling the latest code"
git pull --ff-only origin main

echo "==> PHP dependencies (no dev packages)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Frontend assets"
npm ci
npm run build

echo "==> Database migrations"
php artisan migrate --force

echo "==> Rebuilding caches"
php artisan optimize

echo "==> Restarting PHP-FPM"
sudo systemctl reload php8.3-fpm

echo "==> Maintenance mode off"
php artisan up

echo "Done."
