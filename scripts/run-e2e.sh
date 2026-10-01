#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - end to end test runner
#
# Creates a throwaway administrator in the development database with a password
# that is generated for this run only, then hands it to Playwright through the
# environment. Nothing is written to disk and no credential is ever committed.
#
# The account is removed afterwards, so repeated runs stay clean.
#
# Usage, from the project root:
#     ./scripts/run-e2e.sh
# ---------------------------------------------------------------------------
set -euo pipefail

cd "$(dirname "$0")/.."

EMAIL="e2e@consultora-dh.test"
PASSWORD="$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20)Aa1"

cleanup() {
    echo "--- removing the temporary end to end account"
    docker compose exec -T app php -r '
        $email = $argv[1] ?? null;
        if ($email === null) { exit(0); }
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        App\Models\User::query()->where("email", $email)->delete();
    ' "$EMAIL" >/dev/null 2>&1 || true
}

trap cleanup EXIT

# The sign in endpoint is rate limited to 5 attempts per 5 minutes, per address
# and client. Repeated end to end runs from the same machine would otherwise
# exhaust that budget and test the limiter instead of the application, so the
# development cache is reset first. This only ever runs against local data.
echo "--- resetting the development rate limit counters"
docker compose exec -T app php artisan cache:clear >/dev/null

echo "--- creating the temporary end to end account"
docker compose exec -T \
    -e CONSULTORA_DH_ADMIN_PASSWORD="$PASSWORD" \
    -e CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION="$PASSWORD" \
    app php artisan consultora-dh:create-admin \
        --name="Cuenta E2E" \
        --email="$EMAIL" \
        --no-interaction

echo "--- installing the browser (first run only)"
docker compose exec -T --user root node npx playwright install --with-deps chromium >/dev/null

echo "--- running the suite"
docker compose exec -T \
    -e E2E_EMAIL="$EMAIL" \
    -e E2E_PASSWORD="$PASSWORD" \
    -e APP_URL="http://nginx" \
    node npx playwright test "$@"

status=$?

cleanup

exit $status
