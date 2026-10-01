#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - end to end test runner
#
# Creates two throwaway accounts in the development database, both with passwords
# generated for this run only, and hands them to Playwright through the
# environment. Nothing is written to disk and no credential is ever committed.
#
# Two accounts, because A02 has to prove that authorisation is enforced by the
# server and not merely hidden in the interface. A single administrator can show
# that a button is offered; it cannot show that a request without permission is
# refused. The second account holds the Read Only role, which may read the
# portfolio and change nothing.
#
# Both accounts are removed afterwards, so repeated runs stay clean.
#
# Usage, from the project root:
#     ./scripts/run-e2e.sh
# ---------------------------------------------------------------------------
set -euo pipefail

cd "$(dirname "$0")/.."

# A suffix that makes the accounts of one run identifiable and distinct from
# another, so two runs in a row cannot collide on the unique document constraint.
STAMP="$(date +%s | tail -c 8)"

EMAIL="e2e@consultora-dh.test"
READER_EMAIL="e2e-lectura@consultora-dh.test"

# 20 random characters plus a class mix, so the password satisfies the shared
# policy: 12 characters, mixed case and digits.
random_password() {
    head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20 | sed 's/^[a-z]/A/'
}

PASSWORD="$(random_password)"
READER_PASSWORD="$(random_password)"

cleanup() {
    echo "--- removing the temporary end to end accounts"
    docker compose exec -T app php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        App\Models\User::query()
            ->whereIn("email", array_slice($argv, 1))
            ->delete();
    ' "$EMAIL" "$READER_EMAIL" >/dev/null 2>&1 || true
}

trap cleanup EXIT

# The sign in endpoint is rate limited, so repeated end to end runs from the same
# machine would otherwise exhaust the budget and test the limiter instead of the
# application. This only ever runs against local data.
echo "--- resetting the development rate limit counters"
docker compose exec -T app php artisan cache:clear >/dev/null

echo "--- creating the temporary end to end accounts"
docker compose exec -T \
    -e CONSULTORA_DH_ADMIN_PASSWORD="$PASSWORD" \
    -e CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION="$PASSWORD" \
    app php artisan consultora-dh:create-admin \
        --name="Cuenta E2E" \
        --email="$EMAIL" \
        --role="Super Admin" \
        --no-interaction

docker compose exec -T \
    -e CONSULTORA_DH_ADMIN_PASSWORD="$READER_PASSWORD" \
    -e CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION="$READER_PASSWORD" \
    app php artisan consultora-dh:create-admin \
        --name="Cuenta E2E Lectura" \
        --email="$READER_EMAIL" \
        --role="Read Only" \
        --no-interaction

echo "--- installing the browser (first run only)"
docker compose exec -T --user root node npx playwright install --with-deps chromium >/dev/null

echo "--- running the suite"
docker compose exec -T \
    -e E2E_EMAIL="$EMAIL" \
    -e E2E_PASSWORD="$PASSWORD" \
    -e E2E_READER_EMAIL="$READER_EMAIL" \
    -e E2E_READER_PASSWORD="$READER_PASSWORD" \
    -e E2E_STAMP="$STAMP" \
    -e APP_URL="http://nginx" \
    node npx playwright test "$@"

status=$?

cleanup

exit $status