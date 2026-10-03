#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - end to end test runner
#
# Runs the Playwright suite against a dedicated, disposable copy of the
# application. That copy lives in its own Docker services and its own database:
#
#     development  ->  Postgres consultora_dh     Redis db 0 / cache db 1
#     this suite   ->  Postgres consultora_dh_e2e Redis db 2 / cache db 3
#
# Nothing this script does touches development data, and there is no cleanup step
# that deletes business records, because there is nothing to clean up. That is the
# whole point of the dedicated database, and it is worth stating what replaced what:
#
# The earlier version ran this suite against the development database and then
# removed what it had created by matching a numeric substring: `WHERE
# document_number LIKE '%1234567%'`. That was a guess. It also removed matching
# rows *before* the suite started, so a run could destroy a developer's own
# records without a single test having failed. `consultora-dh:e2e-cleanup`, which
# implemented that matching, has been removed rather than left around: a command
# that deletes development rows by substring is a loaded weapon with no purpose
# left once the suite has its own database.
#
# The database here is disposable, so resetting it is both safe and correct. The
# reset is fail-closed (scripts/reset-e2e-db.sh) and verifies where it is pointed
# before dropping anything.
#
# ## Redis
#
# The suite used to run `cache:clear` against the development application to reset
# rate-limit counters between runs. That discarded a developer's cache. The E2E
# instance has its own Redis databases and key prefixes, and its own session cookie
# name, so counters can simply be left to expire. Nothing here touches the
# development namespace.
#
# ## What the suite writes
#
# Only to `consultora_dh_e2e`. Pass `--keep` to leave the data for inspection after
# a failure; by default it is reset before the run and left in place afterwards for
# the next run to clear, which is cheaper than tearing anything down and impossible
# to get wrong.
#
# Usage, from the project root:
#     ./scripts/run-e2e.sh              # reset, seed, run the suite
#     ./scripts/run-e2e.sh --keep       # leave the data behind afterwards
#     ./scripts/run-e2e.sh --grep @smoke # pass arguments through to Playwright
# ---------------------------------------------------------------------------
set -euo pipefail

cd "$(dirname "$0")/.."

# Both files, always. `app-e2e` exists only in compose.e2e.yaml.
E2E_COMPOSE=(docker compose -f compose.yaml -f compose.e2e.yaml)

KEEP_DATA=0
PLAYWRIGHT_ARGUMENTS=()

for argument in "$@"; do
    case "$argument" in
        --keep) KEEP_DATA=1 ;;
        *)
            PLAYWRIGHT_ARGUMENTS+=("$argument")
            ;;
    esac
done

# A suffix so two runs cannot collide inside the shared database. Still useful for
# the document number constraint, which is unique and not reset by anything else.
STAMP="$(date +%s | tail -c 8)"

EMAIL="e2e@consultora-dh.test"
READER_EMAIL="e2e-lectura@consultora-dh.test"
# The third account holds Collections, which is the role that receives money. A03
# needs it: it can create and apply payments but must not generate obligations,
# and only two roles can show that a permission is enforced rather than hidden.
COLLECTIONS_EMAIL="e2e-cartera@consultora-dh.test"

# A random password that satisfies the shared policy by construction rather than
# by luck: 18 random alphanumerics, plus one upper case letter, one lower case
# letter and one digit. The earlier version uppercased whatever the first random
# character happened to be and then failed the policy whenever the sample had no
# digit at all, which made the suite fail for a reason unrelated to what it tests.
random_password() {
    local random body

    random="$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 18)"
    body="A${random}a7"

    printf '%s' "$body"
}

PASSWORD="$(random_password)"
READER_PASSWORD="$(random_password)"
COLLECTIONS_PASSWORD="$(random_password)"

# --- The URL the suite talks to ---------------------------------------------
#
# `nginx-e2e` is a service name on the Docker network, not a host name. The suite
# runs inside the `node` container, so this is resolvable there and nowhere else.
# `nginx-e2e` publishes no port, so there is nothing for a browser on the host to
# reach and nothing for another process on the machine to discover.
E2E_URL="http://nginx-e2e"

# --- Development state, recorded so it can be proven unchanged ----------------
#
# This is the claim the whole design rests on, so it is measured rather than
# asserted: if the suite wrote a single row into development, this number moves.
development_fingerprint() {
    docker compose exec -T postgres psql -U postgres -d consultora_dh -tAc "
        select
            (select count(*) from clients),
            (select count(*) from companies),
            (select count(*) from social_security_entities),
            (select count(*) from client_company_assignments),
            (select count(*) from client_affiliations),
            (select count(*) from audit_events);
    " 2>/dev/null | tr -d '[:space:]'
}

# --- Start the E2E stack -----------------------------------------------------

# The database first. `app-e2e` migrates on start, so bringing it up against a
# missing database produces a container that boots, fails, and reports itself as
# healthy for a while. Ensuring it exists here means the failure, if there is one, is
# this script's and it can be read in one line.
echo "--- ensuring the end to end database exists"
./scripts/ensure-e2e-db.sh

echo "--- starting the end to end services"
"${E2E_COMPOSE[@]}" up -d app-e2e nginx-e2e node >/dev/null

# `node` runs the suite. Starting it here rather than assuming somebody already did
# is the difference between "the suite runs" and "the suite runs on this machine".
if ! "${E2E_COMPOSE[@]}" ps --status running node 2>/dev/null | grep -q node; then
    echo "!!! The node service did not come up; it runs the suite." >&2
    "${E2E_COMPOSE[@]}" logs --tail 40 node >&2 || true
    exit 1
fi

echo "--- waiting for the end to end web server"
for attempt in $(seq 1 60); do
    if "${E2E_COMPOSE[@]}" ps --status running nginx-e2e 2>/dev/null | grep -q nginx-e2e; then
        break
    fi
    sleep 2
done

if ! "${E2E_COMPOSE[@]}" ps --status running nginx-e2e 2>/dev/null | grep -q nginx-e2e; then
    echo "!!! The end to end web server did not come up." >&2
    "${E2E_COMPOSE[@]}" logs --tail 40 nginx-e2e >&2 || true
    exit 1
fi

# The suite must never be pointed at the development stack. If APP_URL resolves to
# the development host, the run would create records there, which is the exact
# outcome this environment exists to prevent. Cheap to assert, expensive to miss.
RESOLVED_DATABASE="$("${E2E_COMPOSE[@]}" exec -T app-e2e php artisan tinker --execute='
    echo config("database.connections.pgsql.database");
' 2>/dev/null | tr -d '[:space:]')"

EXPECTED_E2E_DATABASE="${DB_E2E_DATABASE:-consultora_dh_e2e}"
DEVELOPMENT_DATABASE="${DB_DATABASE:-consultora_dh}"

if [ "$RESOLVED_DATABASE" != "$EXPECTED_E2E_DATABASE" ]; then
    echo "!!! The E2E application is connected to '$RESOLVED_DATABASE', not '$EXPECTED_E2E_DATABASE'." >&2
    echo "!!! Refusing to run: the suite would write into the wrong database." >&2
    exit 1
fi

if [ "$RESOLVED_DATABASE" = "$DEVELOPMENT_DATABASE" ]; then
    echo "!!! The E2E application is connected to the DEVELOPMENT database." >&2
    echo "!!! Refusing to run." >&2
    exit 1
fi

echo "--- the end to end application is on: $RESOLVED_DATABASE (not $DEVELOPMENT_DATABASE)"

DEVELOPMENT_BEFORE="$(development_fingerprint || echo 'unknown')"
echo "--- development records before the suite: $DEVELOPMENT_BEFORE"

# --- Reset and seed the disposable database ---------------------------------
#
# Fail-closed, and the reset already verified the destination five separate ways.
echo "--- resetting the end to end database"
./scripts/reset-e2e-db.sh --seed

# --- Provision the two accounts, inside the E2E database --------------------
#
# Three accounts, because the suite has to prove that authorisation is enforced by
# the server and not merely hidden in the interface. A single administrator can
# show that a button is offered; it cannot show that a request without permission
# is refused. The Read Only account reads the portfolio and changes nothing, and
# the Collections account receives money while being refused the authority to
# decide what is owed.

echo "--- creating the temporary end to end accounts"
"${E2E_COMPOSE[@]}" exec -T \
    -e CONSULTORA_DH_ADMIN_PASSWORD="$PASSWORD" \
    -e CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION="$PASSWORD" \
    app-e2e php artisan consultora-dh:create-admin \
        --name="Cuenta E2E" \
        --email="$EMAIL" \
        --role="Super Admin" \
        --no-interaction

"${E2E_COMPOSE[@]}" exec -T \
    -e CONSULTORA_DH_ADMIN_PASSWORD="$READER_PASSWORD" \
    -e CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION="$READER_PASSWORD" \
    app-e2e php artisan consultora-dh:create-admin \
        --name="Cuenta E2E Lectura" \
        --email="$READER_EMAIL" \
        --role="Read Only" \
        --no-interaction

"${E2E_COMPOSE[@]}" exec -T \
    -e CONSULTORA_DH_ADMIN_PASSWORD="$COLLECTIONS_PASSWORD" \
    -e CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION="$COLLECTIONS_PASSWORD" \
    app-e2e php artisan consultora-dh:create-admin \
        --name="Cuenta E2E Cartera" \
        --email="$COLLECTIONS_EMAIL" \
        --role="Collections" \
        --no-interaction

# --- Install the browser on first run ----------------------------------------

echo "--- installing the browser (first run only)"
"${E2E_COMPOSE[@]}" exec -T --user root node npx playwright install --with-deps chromium >/dev/null

# --- Run the suite -----------------------------------------------------------

echo "--- running the suite against $E2E_URL"
"${E2E_COMPOSE[@]}" exec -T \
    -e E2E_EMAIL="$EMAIL" \
    -e E2E_PASSWORD="$PASSWORD" \
    -e E2E_READER_EMAIL="$READER_EMAIL" \
    -e E2E_READER_PASSWORD="$READER_PASSWORD" \
    -e E2E_COLLECTIONS_EMAIL="$COLLECTIONS_EMAIL" \
    -e E2E_COLLECTIONS_PASSWORD="$COLLECTIONS_PASSWORD" \
    -e E2E_STAMP="$STAMP" \
    -e E2E_URL="$E2E_URL" \
    -e APP_URL="$E2E_URL" \
    node npx playwright test "${PLAYWRIGHT_ARGUMENTS[@]+"${PLAYWRIGHT_ARGUMENTS[@]}"}"

status=$?

# --- Prove development was never written to ----------------------------------
#
# Not a cleanup step. There is nothing to clean up, because nothing was written
# there. This measures that, and it is allowed to fail the run: a suite that left a
# mark on development has broken the guarantee this environment exists for, and
# that is worth surfacing even when every test passed.
DEVELOPMENT_AFTER="$(development_fingerprint || echo 'unknown')"

echo "--- development records after the suite:  $DEVELOPMENT_AFTER"

if [ "$DEVELOPMENT_BEFORE" != "$DEVELOPMENT_AFTER" ]; then
    echo "!!! DEVELOPMENT DATA CHANGED during the end to end run." >&2
    echo "!!! before: $DEVELOPMENT_BEFORE" >&2
    echo "!!! after:  $DEVELOPMENT_AFTER" >&2
    echo "!!! The six numbers are clients, companies, entities, relationships," >&2
    echo "!!! affiliations and audit events. Nothing is deleted here: this is a report." >&2
    exit 1
fi

echo "    ok   the development database was not written to"

# --- Optionally clear the disposable database -------------------------------
#
# Only ever the E2E one, and only through the fail-closed reset. There is no
# record deletion anywhere in this script, which is the point.
if [ "$KEEP_DATA" -eq 1 ]; then
    echo "--- keeping the end to end data for inspection (--keep)"
else
    echo "--- clearing the end to end database"
    ./scripts/reset-e2e-db.sh >/dev/null
    echo "    ok   $EXPECTED_E2E_DATABASE is empty again"
fi

exit $status
