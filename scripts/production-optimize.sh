#!/usr/bin/env bash
set -euo pipefail

# Keep the shared API serving traffic during release. Deployments must use
# backward-compatible migrations; deliberately entering maintenance mode here
# would interrupt login, checkout, payments and webhook delivery.
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan queue:restart
