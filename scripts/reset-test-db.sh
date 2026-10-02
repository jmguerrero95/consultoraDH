#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - guarded reset of the TEST database
#
# This is the ONLY supported way to rebuild the test database. Do not use
# `php artisan migrate:fresh --env=testing`: `--env=testing` does NOT select the
# testing connection in this project. There is no `.env.testing`, so Laravel
# reads `.env`, the default connection stays `pgsql`, and the command runs
# against the DEVELOPMENT database. That has destroyed local data here twice.
#
# The `beforeEach` guard in tests/Pest.php does not help here: it only runs when
# Pest starts a test, and this script is not Pest.
#
# ## Fail-closed by construction
#
# Nothing destructive happens until every one of these has been verified against
# the configuration Laravel actually resolved, not against what was requested:
#
#   1. database.default is exactly "testing"
#   2. the resolved testing database name is not empty
#   3. the resolved testing database is not the development database
#   4. the resolved testing database matches the expected name
#      (DB_TEST_DATABASE, or "consultora_dh_test")
#   5. PostgreSQL itself reports the connection is on that database
#      (current_database())
#
# If any of them fails the script stops, prints which check failed, and does not
# migrate. Credentials are never printed: the checks report configuration *names*
# and database *names*, never a password or a DSN.
#
# It runs Artisan with APP_ENV=testing and DB_CONNECTION=testing forced, using a
# local PHP when there is one and the project's own container otherwise, so the
# same command works on a development machine and inside Docker.
#
# Usage, from the project root:
#     ./scripts/reset-test-db.sh
#     ./scripts/reset-test-db.sh --seed
#     ./scripts/reset-test-db.sh --seed --force   # skip the "type the database
#                                                 # name" confirmation
# ---------------------------------------------------------------------------
set -euo pipefail

cd "$(dirname "$0")/.."

EXPECTED_TEST_DATABASE="${DB_TEST_DATABASE:-consultora_dh_test}"
SEED=0
ASSUME_YES=0

for argument in "$@"; do
    case "$argument" in
        --seed)
            SEED=1
            ;;
        --force)
            ASSUME_YES=1
            ;;
        -h|--help)
            sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            printf 'Unknown argument: %s\n' "$argument" >&2
            printf 'Usage: ./scripts/reset-test-db.sh [--seed] [--force]\n' >&2
            exit 2
            ;;
    esac
done

# --- How to run Artisan -----------------------------------------------------
#
# Forced every time, never inherited: the whole point is that the environment
# this script migrates under is not negotiable by whatever the caller has set.
#
# Inside the container a local PHP is present. On a host with the project running
# in Docker, `php` is not installed, so the project's own container is used.
run_artisan() {
    # DB_TEST_DATABASE is forwarded when the caller set it, so that what the
    # connection is expected to resolve to and what the script expects are the same
    # value. Without that, check 4 compares a caller's expectation against the
    # container's .env and always passes, and the check that matters (3: the test
    # database must not BE the development database) could never be exercised.
    local -a forwarded=()
    if [ -n "${DB_TEST_DATABASE:-}" ]; then
        forwarded=(-e "DB_TEST_DATABASE=$DB_TEST_DATABASE")
    fi

    if command -v php >/dev/null 2>&1; then
        APP_ENV=testing DB_CONNECTION=testing php artisan "$@"
        return
    fi

    if docker compose ps --status running app >/dev/null 2>&1; then
        docker compose exec -T -e APP_ENV=testing -e DB_CONNECTION=testing "${forwarded[@]}" app php artisan "$@"
        return
    fi

    printf 'Neither a local php nor a running "app" container was found.\n' >&2
    return 1
}

# --- Output helpers ---------------------------------------------------------

RED=""
GREEN=""
BOLD=""
RESET=""
if [ -t 1 ]; then
    RED=$'\033[31m'
    GREEN=$'\033[32m'
    BOLD=$'\033[1m'
    RESET=$'\033[0m'
fi

# Refuse without printing anything secret: the caller asked for a stop, and a
# refusal is not a place to summarise the environment.
refuse() {
    printf '%sRefusing to run migrate:fresh.%s\n' "${RED}${BOLD}" "${RESET}" >&2
    printf '%s\n' "$1" >&2
    printf 'No table was dropped and no migration was run.\n' >&2
    exit 1
}

# --- Check 0: the caller's own environment ---------------------------------
#
# This runs before anything else, and before any Artisan process exists.
#
# The alternative would be to quietly override a hostile environment and do the
# right thing anyway. That is safe for the data and wrong for the operator: they
# asked for a reset of one target and would be told it succeeded against a
# different one, with nothing in the output to say so. A refusal is the honest
# answer, and it is the one an operator can act on.
if [ -n "${DB_CONNECTION:-}" ] && [ "$DB_CONNECTION" != 'testing' ]; then
    refuse "Check 0 failed: DB_CONNECTION is set to '$DB_CONNECTION' in the
    environment, and only 'testing' is safe here. This script never adopts the
    development connection, so a request aimed elsewhere is stopped rather than
    quietly corrected.

    Unset it and try again:

        env -u DB_CONNECTION ./scripts/reset-test-db.sh"
fi

if [ -n "${APP_ENV:-}" ] && [ "$APP_ENV" != 'testing' ]; then
    printf 'Refusing to run migrate:fresh.\n' >&2
    printf "Check 0 failed: APP_ENV is set to '%s', and only 'testing' is safe here.\n" "$APP_ENV" >&2
    printf 'No table was dropped and no migration was run.\n' >&2
    exit 1
fi

step() {
    printf '%s==>%s %s\n' "${BOLD}" "${RESET}" "$1"
}

ok() {
    printf '    %sok%s   %s\n' "${GREEN}" "${RESET}" "$1"
}

# --- Force the environment before Laravel reads it --------------------------
#
# This is the part that makes the script safe: APP_ENV and DB_CONNECTION are set
# here, explicitly, so the resolved configuration cannot depend on what the
# caller forgot or on an `.env` that changes later.
#
# DB_DATABASE is deliberately NOT set to the test database. Leaving it alone
# lets check 3 do its job: if the testing connection were ever pointed at the
# development database, the script would notice, rather than being handed a
# database that was already swapped out from under it.

step 'Resolving the Laravel configuration'

# Ask Laravel itself, through the same bootstrap it uses for the migration, what
# it resolved. Values are printed one per line as key=value.
RESOLVED="$(
    run_artisan tinker --execute='
        $default = config("database.default");
        $testing = config("database.connections.testing.database");
        $development = config("database.connections.pgsql.database");

        printf("default=%s\n", var_export($default, true));
        printf("testing=%s\n", var_export($testing, true));
        printf("development=%s\n", var_export($development, true));
    ' 2>/dev/null || true
)"

if [ -z "$RESOLVED" ]; then
    refuse 'Could not resolve the Laravel configuration.
    The migration was not attempted.

    No local php and no running "app" container were available to resolve the
    configuration with. Start the stack (docker compose up -d) and try again.'
fi

# `var_export` produces PHP literals, so the quotes come off here. Only database
# NAMES are ever handled, never a password or a connection string.
read_config() {
    printf '%s\n' "$RESOLVED" \
        | grep -E "^$1=" \
        | head -n 1 \
        | cut -d= -f2- \
        | tr -d "'" \
        | tr -d '"'
}

RESOLVED_DEFAULT="$(read_config default)"
RESOLVED_TESTING="$(read_config testing)"
RESOLVED_DEVELOPMENT="$(read_config development)"

printf '    database.default        = %s\n' "${RESOLVED_DEFAULT:-<unset>}"
printf '    testing database        = %s\n' "${RESOLVED_TESTING:-<unset>}"
printf '    development database    = %s\n' "${RESOLVED_DEVELOPMENT:-<unset>}"
printf '    expected test database  = %s\n' "$EXPECTED_TEST_DATABASE"

# --- The checks. Every one of them stops the script. ------------------------

# 1. The connection must be the testing one.
if [ "$RESOLVED_DEFAULT" != 'testing' ]; then
    refuse "Check 1 failed: database.default is '${RESOLVED_DEFAULT:-<unset>}', not 'testing'.
    With any other default the command would operate on that connection instead.
    Set DB_CONNECTION=testing, or run this script through scripts/run-tests.sh."
fi
ok 'database.default is testing'

# 2. The test database name must exist at all.
if [ -z "$RESOLVED_TESTING" ] || [ "$RESOLVED_TESTING" = 'NULL' ]; then
    refuse 'Check 2 failed: the testing connection resolved to an empty database name.
    A migration cannot run against nothing, and refusing here is safer than
    letting a default take effect that nobody chose.'
fi
ok "testing database is named: $RESOLVED_TESTING"

# 3. It must not be the development database.
if [ "$RESOLVED_TESTING" = "$RESOLVED_DEVELOPMENT" ]; then
    refuse "Check 3 failed: the testing database and the development database are both
    '$RESOLVED_TESTING'.
    Resetting it would destroy development data. Point DB_TEST_DATABASE at a
    different database and try again."
fi
ok 'testing database differs from the development database'

# 4. It must be the name we expect, so a typo cannot aim at something else.
if [ "$RESOLVED_TESTING" != "$EXPECTED_TEST_DATABASE" ]; then
    refuse "Check 4 failed: the resolved testing database is '$RESOLVED_TESTING',
    but the expected test database is '$EXPECTED_TEST_DATABASE'.
    Refusing rather than trusting an unexpected name."
fi
ok "testing database matches the expected name: $EXPECTED_TEST_DATABASE"

# 5. PostgreSQL must agree about where the connection actually is. This is the
#    check that cannot be fooled by configuration alone: it is the server's own
#    answer to "which database am I on".
step 'Asking PostgreSQL which database the connection is actually on'

ACTUAL="$(
    run_artisan tinker --execute='
        try {
            print DB::connection()->getPdo()
                ->query("select current_database()")->fetchColumn();
        } catch (Throwable $e) {
            // The name of the failure is enough; the message may carry a DSN.
            print "UNREACHABLE:".get_class($e);
        }
    ' 2>/dev/null || true
)"

case "$ACTUAL" in
    UNREACHABLE:*)
        refuse "Check 5 failed: the testing connection could not be verified.
    ${ACTUAL#UNREACHABLE:}
    Nothing was migrated."
        ;;
    '')
        refuse 'Check 5 failed: PostgreSQL did not report a current database.
    Nothing was migrated.'
        ;;
esac

if [ "$ACTUAL" != "$EXPECTED_TEST_DATABASE" ]; then
    refuse "Check 5 failed: PostgreSQL reports current_database() = '$ACTUAL',
    but the expected test database is '$EXPECTED_TEST_DATABASE'.
    The connection is not where the configuration said it would be, so nothing
    was migrated."
fi
ok "PostgreSQL current_database() is $ACTUAL"

# --- Confirmation -----------------------------------------------------------

if [ "$ASSUME_YES" -ne 1 ]; then
    printf '\nAbout to DROP AND RECREATE every table in: %s%s%s\n' "${BOLD}" "$ACTUAL" "${RESET}"
    printf 'This cannot be undone. Type the database name to continue: '

    read -r CONFIRM

    if [ "$CONFIRM" != "$ACTUAL" ]; then
        refuse "Confirmation did not match. Nothing was migrated."
    fi
fi

# --- The only place a destructive command is issued -------------------------

step 'Running migrate:fresh against the verified test database'

run_artisan migrate:fresh --force

if [ "$SEED" -eq 1 ]; then
    step 'Seeding'
    run_artisan db:seed --force
fi

printf '\n%sTest database %s rebuilt.%s\n' "${GREEN}${BOLD}" "$ACTUAL" "${RESET}"
