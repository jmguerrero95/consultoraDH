#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - application container entrypoint
#
# Responsibilities:
#   1. Guarantee that the writable Laravel directories exist.
#   2. Guarantee that PHP dependencies are installed, without printing secrets.
#   3. Apply the E2E environment to the process, via the container's tmpfs and
#      the exported variables — never through the bind-mounted `.env`.
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

# 2. E2E environment ---------------------------------------------------------
# Applied to this process and written to the container's tmpfs, and never to the
# bind-mounted `.env`.
#
# ## Why this is not written to `.env`
#
# The application source is bind-mounted from the host, so `/var/www/html/.env`
# **is the developer's `.env`**. Writing the test environment there replaced a real
# file with E2E values: `APP_ENV=testing`, `DB_DATABASE=consultora_dh_e2e`, the E2E
# Redis and the E2E rate limits. After a single `./scripts/run-e2e.sh`, the
# development instance was pointed at the test database and nine tests that have
# nothing to do with A06 — `RateLimitTest`, `Auth/LoginTest`,
# `A02/EndToEndIsolationTest` — failed, with the developer's real VAPID keys and
# database password replaced by the defaults below. It happened on every run, and
# it happened silently.
#
# `compose.e2e.yaml` already mounts a tmpfs at `/var/www/html/.env.docker` for
# exactly this purpose. The file is generated there, and the values are exported
# into this process's environment, which is what the application actually reads:
# Laravel loads `.env` with an *immutable* dotenv, so a real environment variable
# wins over the file and the host's development `.env` is never consulted for the
# keys that matter here.
#
# The host's `.env` is left exactly as it was found.
if [ "${APP_ENV:-}" = "testing" ] || [ "${APP_ENV:-}" = "e2e" ]; then
    echo "[consultora-dh] applying the E2E environment (tmpfs + process env)..."

    ENV_DIR="/var/www/html/.env.docker"
    ENV_FILE="$ENV_DIR/env"

    mkdir -p "$ENV_DIR"

    # Start from the example as a template, for any key this script does not set.
    if [ -f .env.example ]; then
        cp .env.example "$ENV_FILE"
    else
        : > "$ENV_FILE"
    fi

    # Record the value in the tmpfs copy *and* put it in this process's
    # environment. The export is what the application reads.
    set_env() {
        local key="$1"
        local value="$2"
        local escaped_value
        escaped_value=$(printf '%s\n' "$value" | sed 's/[&/\]/\\&/g')
        if grep -qE "^${key}=" "$ENV_FILE"; then
            sed -i "s|^${key}=.*|${key}=${escaped_value}|" "$ENV_FILE"
        else
            echo "${key}=${escaped_value}" >> "$ENV_FILE"
        fi
        export "${key}=${value}"
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

    # Ensure APP_KEY exists, without writing one to the host.
    #
    # `key:generate --force` writes to `.env`, which is the file this whole block
    # exists to avoid touching. `--show` prints the key instead, so it is
    # exported here and recorded in the tmpfs copy.
    if [ -z "${APP_KEY:-}" ] && ! grep -qE '^APP_KEY=.+' "$ENV_FILE"; then
        echo "[consultora-dh] APP_KEY missing - generating one for this process..."
        GENERATED_KEY="$(php artisan key:generate --show 2>/dev/null | tail -n 1 | tr -d '\r')"
        if [ -n "$GENERATED_KEY" ]; then
            export APP_KEY="$GENERATED_KEY"
            echo "APP_KEY=${GENERATED_KEY}" >> "$ENV_FILE"
        fi
    fi

    if [ -n "${APP_KEY:-}" ]; then
        export APP_KEY
    elif grep -qE '^APP_KEY=.+' "$ENV_FILE"; then
        export APP_KEY="$(grep '^APP_KEY=' "$ENV_FILE" | cut -d'=' -f2-)"
    fi

    echo "[consultora-dh] E2E environment ready (host .env untouched)"
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
#
# Skipped entirely in the E2E environment, where `.env` is the host's development
# file: writing a key there would change the developer's configuration, and the
# key this container needs was already exported in step 2.
if [ "${APP_ENV:-}" != "testing" ] && [ "${APP_ENV:-}" != "e2e" ]; then
    if [ -f artisan ] && [ -f .env ] && ! grep -qE '^APP_KEY=.+' .env; then
        echo "[consultora-dh] APP_KEY missing - generating a new key..."
        php artisan key:generate --ansi --force >/dev/null
    fi
fi

exec "$@"