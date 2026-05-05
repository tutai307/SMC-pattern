#!/bin/bash
set -e

echo "[start] Running migrations..."
php artisan migrate --force

echo "[start] Starting queue worker..."
php artisan queue:listen --sleep=3 --tries=3 &

echo "[start] Starting scheduler..."
php artisan schedule:work &

echo "[start] Starting signal monitor..."
php artisan signals:monitor &

echo "[start] Starting Telegram bot..."
php artisan telegram:bot &

echo "[start] Starting web server on port ${PORT:-8000}..."
exec php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
