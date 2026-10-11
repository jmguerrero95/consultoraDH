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

EMAIL="e2e-staff@consultora-dh.test"
READER_EMAIL="e2e-client-a@consultora-dh.test"
# The third account holds Collections, which is the role that receives money. A03
# needs it: it can create and apply payments but must not generate obligations,
# and only two roles can show that a permission is enforced rather than hidden.
COLLECTIONS_EMAIL="e2e-client-b@consultora-dh.test"

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

PASSWORD="Password1234"
READER_PASSWORD="Password1234"
COLLECTIONS_PASSWORD="Password1234"

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
#
# §49. The fingerprint covered six A02 tables. A03 added seven more, and every one of them
# holds financial records, so "the E2E environment never writes development data" now covers
# the directory **and** the money. Without them the guarantee was stated more broadly than it
# was measured: the suite could have written a payment into development and the number would
# not have moved.
#
# The database is the **configured** one, not a literal. The claim this script makes is that
# the configured development database was not written to, and it used to fingerprint a
# hardcoded `consultora_dh` — so a developer who set `DB_DATABASE` to something else was
# told their real database was safe on the strength of an untouched different one. The
# identifier is validated before it reaches SQL, and the function refuses rather than
# falling back to a default.
development_fingerprint() {
    local db="$DEVELOPMENT_DATABASE"

    # Fail closed on anything that is not a plain lowercase identifier. A name arriving with
    # a quote in it would be executed as part of the statement.
    case "$db" in
        "" | *[!a-z0-9_]* | [0-9]* )
            echo "!!! The configured development database name is not a plain identifier: '$db'." >&2
            echo "!!! Refusing to measure a database whose name cannot be trusted." >&2
            return 1
            ;;
    esac

    docker compose exec -T postgres psql -U postgres -d "$db" -tAc "
        select
            (select count(*) from clients),
            (select count(*) from companies),
            (select count(*) from social_security_entities),
            (select count(*) from client_company_assignments),
            (select count(*) from client_affiliations),
            (select count(*) from audit_events),
            (select count(*) from monthly_periods),
            (select count(*) from cutoff_rules),
            (select count(*) from client_company_rates),
            (select count(*) from monthly_obligations),
            (select count(*) from obligation_adjustments),
            (select count(*) from payments),
            (select count(*) from payment_allocations);
    " 2>/dev/null | tr -d '[:space:]'
}

# --- Start the E2E stack -----------------------------------------------------

# The database first. `app-e2e` migrates on start, so bringing it up against a
# missing database produces a container that boots, fails, and reports itself as
# healthy for a while. Ensuring it exists here means the failure, if there is one, is
# this script's and it can be read in one line.
echo "--- ensuring the end to end database exists"
./scripts/ensure-e2e-db.sh

# Recreated, not merely started.
#
# `up -d` on an already-running container leaves it running, and that is wrong for a suite that
# has to measure *this* tree. `queue:work` is a long-lived PHP process: it boots once and holds
# every class it has touched in memory for its whole life, so a worker started before an edit
# keeps executing the old code with no warning. `app-e2e`'s PHP-FPM revalidates timestamps, so
# the two halves of the same request would disagree — the route dispatched new code, the job that
# route dispatched ran old code.
#
# The symptom is worse than a failure. During A04-R2 the suite reported an import as applicable
# while a date blocker was open, and the only reason was that the worker had not been restarted
# since the fix that stopped it. A suite that can quietly test yesterday's code cannot be used to
# decide whether yesterday's bug is gone, so the services are recreated on every run.
echo "--- starting the end to end services"
"${E2E_COMPOSE[@]}" up -d --force-recreate app-e2e nginx-e2e node queue-e2e >/dev/null

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

# A refusal here is a refusal to make the claim at all, which is the point of §50.
if ! DEVELOPMENT_BEFORE="$(development_fingerprint)"; then
    echo "!!! Could not fingerprint the configured development database." >&2
    exit 1
fi
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

# --- Clear the disposable instance's own sign-in counters --------------------
#
# §48. A second run within five minutes could not sign in at all, and the readiness gate
# below correctly refused to start a suite it knew would fail. The cause is the product's
# own sign-in limiter — twenty attempts per address per five minutes, thirty per account per
# fifteen — counting every run, and the three accounts this script creates are the same
# three every time, from the same address.
#
# That limiter is correct and is not touched. What is disposable is **this instance's
# counters**: they live in the end to end Redis namespace, which is created for this script
# and belongs to nobody. The development namespace is not involved, and the earlier
# `cache:clear` against development — the thing that used to solve exactly this problem and
# wrongly — is not what is happening here.
#
# Fail-closed as everywhere else: if the resolved cache database or prefix is not the end to
# end one, nothing is deleted. A key pattern that does not start with the expected prefix
# would match the wrong keys, and deleting the wrong keys is worse than not clearing them.
echo "--- clearing the end to end instance's sign-in counters"

# Read from the Redis *connection* config, not from the cache store: Laravel's cache
# prefix and store name live in `cache.php`, but the database number of a Redis
# connection lives in `database.php`, and asking the cache store for a key it does not
# have answers null — which would read as "wrong database" and refuse forever.
RESOLVED_CACHE_DB="$("${E2E_COMPOSE[@]}" exec -T app-e2e php artisan tinker --execute='
    echo config("database.redis.cache.database");
' 2>/dev/null | tr -d '[:space:]')"

RESOLVED_CACHE_PREFIX="$("${E2E_COMPOSE[@]}" exec -T app-e2e php artisan tinker --execute='
    echo config("cache.prefix");
' 2>/dev/null | tr -d '[:space:]')"

EXPECTED_CACHE_DB="${REDIS_CACHE_DB:-3}"
EXPECTED_CACHE_PREFIX="${CACHE_PREFIX:-consultora-dh-e2e-cache-}"

if [ "$RESOLVED_CACHE_DB" != "$EXPECTED_CACHE_DB" ] \
    || [ "$RESOLVED_CACHE_PREFIX" != "$EXPECTED_CACHE_PREFIX" ]; then
    echo "!!! The end to end cache is on db '$RESOLVED_CACHE_DB' with prefix '$RESOLVED_CACHE_PREFIX'," >&2
    echo "!!! not on db '$EXPECTED_CACHE_DB' with prefix '$EXPECTED_CACHE_PREFIX'." >&2
    echo "!!! Refusing to clear anything: a key pattern that does not start with the expected" >&2
    echo "!!! prefix would match the wrong keys." >&2
    exit 1
fi

# The password is not inside the redis container — compose interpolates it into the
# healthcheck rather than passing it in — so it is read from the configuration the
# end to end application itself resolved. It is never printed: the only thing this
# script reports about the cache is the database number, the prefix and a count.
#
# `artisan cache:clear` would be shorter and is **not** used: Laravel flushes a Redis
# store with `flushdb()`, which ignores the key prefix entirely. The prefix is the
# reason a misconfigured connection cannot reach another namespace's keys, so a
# command that ignores it is exactly the wrong tool here even on a disposable
# instance.
E2E_REDIS_PASSWORD="$("${E2E_COMPOSE[@]}" exec -T app-e2e php artisan tinker --execute='
    echo (string) config("database.redis.cache.password");
' 2>/dev/null | tr -d '\r\n')"

if [ -z "$E2E_REDIS_PASSWORD" ]; then
    echo "!!! The end to end Redis password could not be resolved, so its counters" >&2
    echo "!!! cannot be cleared. Refusing to start a suite that would then be" >&2
    echo "!!! refused its own sign in." >&2
    exit 1
fi

redis_on_e2e() {
    "${E2E_COMPOSE[@]}" exec -T \
        -e REDIS_PASSWORD="$E2E_REDIS_PASSWORD" \
        redis sh -c 'redis-cli --no-auth-warning -a "$REDIS_PASSWORD" "$@"' sh "$@" </dev/null
}

# Only keys carrying this instance's own prefix, on this instance's own database number.
E2E_SIGN_IN_KEYS="$(redis_on_e2e -n "$RESOLVED_CACHE_DB" --scan --pattern "${RESOLVED_CACHE_PREFIX}*" | tr -d '\r')"

E2E_SIGN_IN_COUNT="$(printf '%s\n' "$E2E_SIGN_IN_KEYS" | grep -c . || true)"

if [ "$E2E_SIGN_IN_COUNT" -gt 0 ]; then
    while IFS= read -r key; do
        [ -n "$key" ] || continue

        redis_on_e2e -n "$RESOLVED_CACHE_DB" del "$key" >/dev/null
    done <<EOF
$E2E_SIGN_IN_KEYS
EOF

    printf '    ok   %s end to end cache key(s) cleared, all of them on db %s with prefix %s\n' \
        "$E2E_SIGN_IN_COUNT" "$RESOLVED_CACHE_DB" "$RESOLVED_CACHE_PREFIX"
else
    printf '    ok   nothing to clear: db %s held no key with prefix %s\n' \
        "$RESOLVED_CACHE_DB" "$RESOLVED_CACHE_PREFIX"
fi

# --- Wait until the disposable application can actually sign somebody in --------
#
# The suite spends its first seconds signing in, and every journey depends on that. The
# seed above is asynchronous: the accounts are created through Artisan while the
# application server is already answering requests, so a run that starts immediately can
# reach the login screen before the accounts exist. When it does, `POST /login` answers 500
# and *every* test fails on a URL mismatch — which reads like a broken product and is
# really a race in the harness.
#
# Probing the real endpoint, with the real credentials, is the only readiness signal worth
# having: a 200 from the login *page* proves nothing, because that page is static and answers
# happily while the accounts are still being written.
echo "--- waiting for the end to end application to accept a sign in"

SIGN_IN_READY=0

for attempt in $(seq 1 30); do
    # Probed from the node container, which is the same network the suite will use and the
    # only one that speaks HTTP: `app-e2e` is PHP-FPM and has no server of its own.
    if "${E2E_COMPOSE[@]}" exec -T \
        -e PROBE_URL="$E2E_URL/api/auth/login" \
        -e PROBE_EMAIL="$EMAIL" \
        -e PROBE_PASSWORD="$PASSWORD" \
        node node -e '
            const body = JSON.stringify({
                email: process.env.PROBE_EMAIL,
                password: process.env.PROBE_PASSWORD,
            });

            // The same order a browser uses: take the CSRF cookie, then echo it back in
            // the header. Skipping this gets a 419 and a message about an expired session,
            // which says nothing about whether the accounts exist.
            (async () => {
                const origin = process.env.PROBE_URL.replace(/\/api\/auth\/login$/, "");

                const csrf = await fetch(new URL("/sanctum/csrf-cookie", origin), {
                    credentials: "include",
                });

                // The fetch built into node keeps no cookie jar, so both cookies are
                // collected here and the session one is sent back by hand. Dropping that
                // one is a 419.
                const jar = (csrf.headers.getSetCookie?.() ?? []).map((entry) =>
                    entry.split(";")[0],
                );

                const cookie = jar.find((entry) => entry.startsWith("XSRF-TOKEN="));

                if (!cookie) {
                    process.exit(1);
                }

                const token = decodeURIComponent(cookie.slice("XSRF-TOKEN=".length));

                const response = await fetch(process.env.PROBE_URL, {
                    method: "POST",
                    credentials: "include",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        "X-XSRF-TOKEN": token,
                        Cookie: jar.join("; "),
                    },
                    body: JSON.stringify({
                        email: process.env.PROBE_EMAIL,
                        password: process.env.PROBE_PASSWORD,
                    }),
                    redirect: "manual",
                });

                process.exit(response.status === 200 ? 0 : 1);
            })().catch(() => process.exit(1));
        ' >/dev/null 2>&1; then
        SIGN_IN_READY=1
        printf '    ready after %s attempt(s).\n' "$attempt"
        break
    fi

    sleep 2
done

if [ "$SIGN_IN_READY" -ne 1 ]; then
    printf 'The end to end application never accepted a sign in after 60 seconds.\n' >&2
    printf 'Refusing to run the suite: every journey would fail on a login it never got.\n' >&2
    exit 1
fi

# --- Run the suite -----------------------------------------------------------

echo "--- running the suite against $E2E_URL"

# The status is captured rather than allowed to terminate the script.
#
# §51. With `set -e`, a failing Playwright run stopped everything below it — which meant the
# development fingerprint was never taken afterwards, the comparison never happened, and the
# cleanup and the diagnostic output never ran. So the one moment the safety verification
# matters most, the run that failed, was exactly the run that skipped it. A suite that left
# a mark on development during a failing run reported nothing.
if "${E2E_COMPOSE[@]}" exec -T \
    -e E2E_EMAIL="$EMAIL" \
    -e E2E_PASSWORD="$PASSWORD" \
    -e E2E_READER_EMAIL="$READER_EMAIL" \
    -e E2E_READER_PASSWORD="$READER_PASSWORD" \
    -e E2E_COLLECTIONS_EMAIL="$COLLECTIONS_EMAIL" \
    -e E2E_COLLECTIONS_PASSWORD="$COLLECTIONS_PASSWORD" \
    -e E2E_STAMP="$STAMP" \
    -e E2E_URL="$E2E_URL" \
    -e APP_URL="$E2E_URL" \
    node npx playwright test "${PLAYWRIGHT_ARGUMENTS[@]+"${PLAYWRIGHT_ARGUMENTS[@]}"}"; then
    status=0
else
    status=$?
    echo "--- the suite failed (status $status); the safety verification still runs"
fi

safety_status=0

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
    echo "!!! The thirteen numbers are clients, companies, entities, relationships," >&2
    echo "!!! affiliations, audit events, periods, cutoff rules, rates, obligations," >&2
    echo "!!! adjustments, payments and allocations." >&2
    echo "!!! Nothing is deleted here: this is a report." >&2
    safety_status=1
else
    echo "    ok   the development database was not written to"
fi

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

# A safety failure is reported even when the tests passed, and it is not hidden by the test
# result: a green run that touched development is not a green run.
if [ "$safety_status" -ne 0 ]; then
    echo "!!! The suite is not trusted: development data changed." >&2
    exit 1
fi

exit $status
