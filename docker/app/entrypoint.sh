#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - application container entrypoint
#
# Responsibilities:
#   1. Guarantee that the writable Laravel directories exist.
#   2. Guarantee that PHP dependencies are installed, without printing secrets.
#   3. Generate E2E environment file in /tmp (not bind-mounted).
#   4. Hand over control to the requested command (php-fpm, artisan, ...).
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

# 2. E2E environment file generation ------------------------------------------
# In the E2E environment, we overwrite the bind-mounted .env file with E2E values.
# The host's .env file will be restored manually or from .env.example after E2E runs.
if [ "${APP_ENV:-}" = "testing" ] || [ "${APP_ENV:-}" = "e2e" ]; then
    echo "[consultora-dh] Generating E2E environment in bind-mounted .env..."

    ENV_FILE="/var/www/html/.env"

    # Start with the example as a template
    if [ -f .env.example ]; then
        cp .env.example "$ENV_FILE"
    else
        touch "$ENV_FILE"
    fi

    # Override with E2E-specific values from environment variables
    set_env() {
        local key="$1"
        local value="$2"
        local escaped_value=$(printf '%s\n' "$value" | sed 's/[&/\]/\\&/g')
        if grep -qE "^${key}=" "$ENV_FILE"; then
            sed -i "s|^${key}=.*|${key}=${escaped_value}|" "$ENV_FILE"
        else
            echo "${key}=${escaped_value}" >> "$ENV_FILE"
        fi
    }

    # Core application
    set_env "APP_ENV" "${APP_ENV:-testing}"
    set_env "APP_DEBUG" "${APP_DEBUG:-false}"
    set_env "APP_URL" "${APP_URL:-http://nginx-e2e}"
    set_env "TRUSTED_HOSTS" "${TRUSTED_HOSTS:-nginx-e2e,nginx-e2e.local,localhost,127.0.0.1}"

    # Database
    set_env "DB_CONNECTION" "${DB_CONNECTION:-pgsql}"
    set_env "DB_HOST" "${DB_HOST:-postgres}"
    set_env "DB_DATABASE" "${DB_DATABASE:-consultora_dh_e2e}"
    set_env "DB_USERNAME" "${DB_USERNAME:-consultora_dh_app}"
    set_env "DB_PASSWORD" "${DB_PASSWORD:-}"

    # Redis
    set_env "REDIS_HOST" "${REDIS_HOST:-redis}"
    set_env "REDIS_DB" "${REDIS_DB:-2}"
    set_env "REDIS_CACHE_DB" "${REDIS_CACHE_DB:-3}"
    set_env "REDIS_PREFIX" "${REDIS_PREFIX:-consultora-dh-e2e-}"
    set_env "CACHE_PREFIX" "${CACHE_PREFIX:-consultora-dh-e2e-cache-}"
    set_env "CACHE_STORE" "${CACHE_STORE:-redis}"
    set_env "SESSION_DRIVER" "${SESSION_DRIVER:-redis}"
    set_env "SESSION_COOKIE" "${SESSION_COOKIE:-consultora_dh_e2e_session}"
    set_env "QUEUE_CONNECTION" "${QUEUE_CONNECTION:-redis}"

    # Rate limits
    set_env "API_RATE_LIMIT_PER_MINUTE" "${API_RATE_LIMIT_PER_MINUTE:-2000}"
    set_env "LOGIN_RATE_LIMIT_PER_ATTEMPT" "${LOGIN_RATE_LIMIT_PER_ATTEMPT:-2000}"
    set_env "LOGIN_RATE_LIMIT_PER_ACCOUNT" "${LOGIN_RATE_LIMIT_PER_ACCOUNT:-2000}"

    # Support email HMAC (use test secret)
    set_env "SUPPORT_EMAIL_HMAC_SECRET" "${SUPPORT_EMAIL_HMAC_SECRET:-test-secret}"

    # VAPID keys for Web Push (generate dummy if not provided)
    if [ -z "${VAPID_PUBLIC_KEY:-}" ] || [ -z "${VAPID_PRIVATE_KEY:-}" ]; then
        set_env "VAPID_PUBLIC_KEY" "dummy-public-key"
        set_env "VAPID_PRIVATE_KEY" "dummy-private-key"
        set_env "VAPID_SUBJECT" "mailto:test@e2e.local"
    else
        set_env "VAPID_PUBLIC_KEY" "${VAPID_PUBLIC_KEY}"
        set_env "VAPID_PRIVATE_KEY" "${VAPID_PRIVATE_KEY}"
        set_env "VAPID_SUBJECT" "${VAPID_SUBJECT:-mailto:test@e2e.local}"
    fi

    # Ensure APP_KEY exists
    if ! grep -qE '^APP_KEY=' "$ENV_FILE" || [ -z "$(grep '^APP_KEY=' "$ENV_FILE" | cut -d'=' -f2-)" ]; then
        echo "[consultora-dh] APP_KEY missing - generating a new key..."
        php artisan key:generate --ansi --force >/dev/null
    fi

    echo "[consultora-dh] E2E environment generated in $ENV_FILE"
fi

# 3. Dependencies --------------------------------------------------------------
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

# 4. Application key ----------------------------------------------------------
# Generated once and persisted in the .env file, which is never committed.
# Skipped while the application is not installed yet (first bootstrap run).
if [ -f artisan ] && [ -f .env ] && ! grep -qE '^APP_KEY=.+' .env; then
    echo "[consultora-dh] APP_KEY missing - generating a new key..."
    php artisan key:generate --ansi --force >/dev/null
fi

exec "$@"