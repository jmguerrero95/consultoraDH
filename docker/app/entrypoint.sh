#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - application container entrypoint
#
# Responsibilities:
#   1. Guarantee that the writable Laravel directories exist.
#   2. Guarantee that PHP dependencies are installed, without printing secrets.
#   3. Hand over control to the requested command (php-fpm, artisan, ...).
# ---------------------------------------------------------------------------
set -euo pipefail

cd /var/www/html

# 1. Writable directories -----------------------------------------------------
# The application source is bind-mounted from the host, so the storage tree may
# be missing or stale between runs.
for dir in \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/public \
    bootstrap/cache
do
    mkdir -p "$dir"
done

chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

# The PHP-FPM pool runs as `www-data` and needs to write compiled views, the
# framework cache and the application log.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# 2. Dependencies --------------------------------------------------------------
# `vendor/` is a named volume so that tens of thousands of files are not read
# and written through the host bind mount. It therefore starts empty, and we
# install dependencies on first boot (and whenever the lock file changes).
if [ ! -f vendor/autoload.php ]; then
    echo "[consultora-dh] vendor/ not found - installing PHP dependencies..."
    if [ ! -f composer.json ]; then
        echo "[consultora-dh] composer.json is missing; skipping dependency install." >&2
    else
        composer install --no-interaction --prefer-dist --no-scripts --no-progress
        php artisan package:discover --ansi >/dev/null 2>&1 || true
    fi
fi

# 3. Application key ----------------------------------------------------------
# Generated once and persisted in the .env file, which is never committed.
# Skipped while the application is not installed yet (first bootstrap run).
if [ -f artisan ] && [ -f .env ] && ! grep -qE '^APP_KEY=.+' .env; then
    echo "[consultora-dh] APP_KEY missing - generating a new key..."
    php artisan key:generate --ansi --force >/dev/null
fi

exec "$@"
