#!/usr/bin/env bash
set -euo pipefail
php artisan down || true
trap 'php artisan up' EXIT
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan queue:restart
php artisan up
trap - EXIT
