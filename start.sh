#!/bin/bash

echo "[start] Running migrations..."
php artisan migrate --force || echo "[start] WARNING: migrations failed, continuing anyway..."

set -e

# Auto-restart wrapper: nếu process crash thì tự restart sau 5s
respawn() {
    local name="$1"
    shift
    while true; do
        echo "[respawn] Starting $name..."
        "$@" || true
        echo "[respawn] $name exited — restarting in 5s..."
        sleep 5
    done
}

echo "[start] Starting background workers..."
respawn "queue"   php artisan queue:listen --sleep=3 --tries=3 &
respawn "scheduler" php artisan schedule:work &
respawn "monitor" php artisan signals:monitor &
respawn "telegram" php artisan telegram:bot &
respawn "scanner"  php artisan signals:scan --interval=${SCAN_INTERVAL:-300} &

echo "[start] Starting web server on port ${PORT:-8000}..."
exec php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
