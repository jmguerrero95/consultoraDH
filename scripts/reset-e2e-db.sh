#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - guarded reset of the END TO END database
#
# The counterpart of scripts/reset-test-db.sh for the Playwright suite, and built
# on the same principle: nothing destructive happens until the destination has been
# verified from the configuration Laravel actually resolved and from PostgreSQL
# itself.
#
# This database is disposable by construction. It holds nothing a person cares
# about, and a run of the suite is expected to fill it and leave it full. That is
# exactly why it is safe to drop and rebuild, and why the guards below are about
# making sure THIS database is the one being dropped.
#
# ## The guards, all of which stop the script before migrating
#
#   0. the caller's own environment does not point anywhere else
#   1. the application environment is E2E/testing
#   2. the resolved database name is exactly the expected E2E database
#   3. it differs from the development database
#   4. it differs from the Pest test database
#   5. PostgreSQL reports current_database() as the expected name
#
# Checks 3 and 4 are the ones that matter most. They are why the name is compared
# against all three known databases rather than only against the expected one: a
# typo in the configuration would otherwise satisfy check 2 failing open, or a
# misconfigured variable would quietly point the drop at development.
#
# No credential is ever printed. The checks report configuration names and
# database names.
#
# Usage, from the project root:
#     ./scripts/reset-e2e-db.sh              # rebuild, empty
#     ./scripts/reset-e2e-db.sh --seed       # rebuild and load roles/permissions
# ---------------------------------------------------------------------------
set -euo pipefail

cd "$(dirname "$0")/.."

EXPECTED_E2E_DATABASE="${DB_E2E_DATABASE:-consultora_dh_e2e}"
DEVELOPMENT_DATABASE="${DB_DATABASE:-consultora_dh}"
TEST_DATABASE="${DB_TEST_DATABASE:-consultora_dh_test}"
SEED=0

for argument in "$@"; do
    case "$argument" in
        --seed) SEED=1 ;;
        -h|--help)
            sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            printf 'Unknown argument: %s\n' "$argument" >&2
            printf 'Usage: ./scripts/reset-e2e-db.sh [--seed]\n' >&2
            exit 2
            ;;
    esac
done

# --- How to run Artisan -----------------------------------------------------
#
# The E2E application container is used when it is up, because it already carries
# the E2E environment: database `consultora_dh_e2e`, `APP_ENV=testing`, its own
# Redis namespace. Running the migration through the development container would
# migrate whatever that container is configured for, which is the opposite of what
# this script is for.
#
# Without a container, a local PHP with the environment forced works, which is how
# the script is used on a machine that is not running Docker.
# Both compose files are named explicitly. `app-e2e` only exists in compose.e2e.yaml,
# and relying on compose finding it by container name when only compose.yaml is
# loaded would be relying on behaviour that is not part of the contract.
E2E_COMPOSE=(docker compose -f compose.yaml -f compose.e2e.yaml)

run_artisan() {
    if "${E2E_COMPOSE[@]}" ps --status running app-e2e >/dev/null 2>&1; then
        "${E2E_COMPOSE[@]}" exec -T app-e2e php artisan "$@"
        return
    fi

    if command -v php >/dev/null 2>&1; then
        APP_ENV=testing \
        DB_CONNECTION=pgsql \
        DB_DATABASE="$EXPECTED_E2E_DATABASE" \
            php artisan "$@"
        return
    fi

    printf 'Neither a running "app-e2e" container nor a local php was found.\n' >&2
    printf 'Start the stack with:\n\n    docker compose -f compose.yaml -f compose.e2e.yaml up -d\n' >&2
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

refuse() {
    printf '%sRefusing to run migrate:fresh.%s\n' "${RED}${BOLD}" "${RESET}" >&2
    printf '%s\n' "$1" >&2
    printf 'No table was dropped and no migration was run.\n' >&2
    exit 1
}

step() { printf '%s==>%s %s\n' "${BOLD}" "${RESET}" "$1"; }
ok()   { printf '    %sok%s   %s\n' "${GREEN}" "${RESET}" "$1"; }

# --- Check 0: the caller's own environment ----------------------------------
#
# A database name in the environment that is not the E2E one is the exact
# situation this script must refuse rather than correct. Correcting it quietly
# would mean dropping a database the caller did not name while reporting success.
if [ -n "${DB_DATABASE:-}" ] && [ "$DB_DATABASE" != "$EXPECTED_E2E_DATABASE" ]; then
    refuse "Check 0 failed: DB_DATABASE is set to '$DB_DATABASE' in the
    environment, and only '$EXPECTED_E2E_DATABASE' is safe here.

    This script never adopts another database, so nothing was migrated. Unset it
    and try again:

        env -u DB_DATABASE ./scripts/reset-e2e-db.sh"
fi

if [ -n "${APP_ENV:-}" ] && [ "$APP_ENV" != 'testing' ] && [ "$APP_ENV" != 'e2e' ]; then
    refuse "Check 0 failed: APP_ENV is set to '$APP_ENV', and only 'testing' (or
    'e2e') is safe here. Nothing was migrated."
fi

# --- Resolve the configuration ----------------------------------------------

step 'Resolving the Laravel configuration of the E2E application'

RESOLVED="$(run_artisan tinker --execute='
    printf("environment=%s\n", var_export(app()->environment(), true));
    printf("default=%s\n", var_export(config("database.default"), true));
    printf("database=%s\n", var_export(config("database.connections.pgsql.database"), true));
' 2>/dev/null || true)"

if [ -z "$RESOLVED" ]; then
    refuse 'Could not resolve the Laravel configuration, so nothing was migrated.
    The E2E application must be running. Start it with:

        docker compose -f compose.yaml -f compose.e2e.yaml up -d'
fi

read_config() {
    printf '%s\n' "$RESOLVED" \
        | grep -E "^$1=" \
        | head -n 1 \
        | cut -d= -f2- \
        | tr -d "'" \
        | tr -d '"'
}

RESOLVED_ENVIRONMENT="$(read_config environment)"
RESOLVED_DEFAULT="$(read_config default)"
RESOLVED_DATABASE="$(read_config database)"

printf '    application environment = %s\n' "${RESOLVED_ENVIRONMENT:-<unset>}"
printf '    database.default        = %s\n' "${RESOLVED_DEFAULT:-<unset>}"
printf '    resolved database       = %s\n' "${RESOLVED_DATABASE:-<unset>}"
printf '    expected e2e database   = %s\n' "$EXPECTED_E2E_DATABASE"

# --- Check 1: the application environment is the test one -------------------

if [ "$RESOLVED_ENVIRONMENT" != 'testing' ] && [ "$RESOLVED_ENVIRONMENT" != 'e2e' ]; then
    refuse "Check 1 failed: the application environment is '${RESOLVED_ENVIRONMENT:-<unset>}'.
    Only 'testing' (or 'e2e') may be reset, so that this cannot be pointed at a
    development installation by accident."
fi
ok "the application environment is $RESOLVED_ENVIRONMENT"

# --- Check 2: the resolved name is the expected one -------------------------

if [ "$RESOLVED_DATABASE" != "$EXPECTED_E2E_DATABASE" ]; then
    refuse "Check 2 failed: the resolved database is '${RESOLVED_DATABASE:-<unset>}',
    but the expected end to end database is '$EXPECTED_E2E_DATABASE'."
fi
ok "the resolved database is $EXPECTED_E2E_DATABASE"

# --- Checks 3 and 4: it is neither development nor the Pest database --------

if [ "$RESOLVED_DATABASE" = "$DEVELOPMENT_DATABASE" ]; then
    refuse "Check 3 failed: '$RESOLVED_DATABASE' is the DEVELOPMENT database.
    Refusing absolutely. Nothing was migrated."
fi
ok "it differs from the development database ($DEVELOPMENT_DATABASE)"

if [ "$RESOLVED_DATABASE" = "$TEST_DATABASE" ]; then
    refuse "Check 4 failed: '$RESOLVED_DATABASE' is the Pest test database.
    Resetting it here would rebuild the unit test schema behind the suite's back.
    Nothing was migrated."
fi
ok "it differs from the Pest test database ($TEST_DATABASE)"

# --- Check 5: PostgreSQL agrees about where the connection is ---------------

step 'Asking PostgreSQL which database the connection is actually on'

ACTUAL="$(run_artisan tinker --execute='
    try {
        print DB::connection()->getPdo()->query("select current_database()")->fetchColumn();
    } catch (Throwable $e) {
        // The exception class is enough; the message may carry a DSN.
        print "UNREACHABLE:".get_class($e);
    }
' 2>/dev/null || true)"

case "$ACTUAL" in
    UNREACHABLE:*)
        refuse "Check 5 failed: the connection could not be verified.
    ${ACTUAL#UNREACHABLE:}
    Nothing was migrated."
        ;;
    '')
        refuse 'Check 5 failed: PostgreSQL did not report a current database.
    Nothing was migrated.'
        ;;
esac

if [ "$ACTUAL" != "$EXPECTED_E2E_DATABASE" ]; then
    refuse "Check 5 failed: PostgreSQL reports current_database() = '$ACTUAL',
    but the expected end to end database is '$EXPECTED_E2E_DATABASE'.
    The connection is not where the configuration said it would be, so nothing
    was migrated."
fi
ok "PostgreSQL current_database() is $ACTUAL"

# --- The only place a destructive command is issued -------------------------

step "Rebuilding $ACTUAL"

run_artisan migrate:fresh --force

if [ "$SEED" -eq 1 ]; then
    step 'Seeding roles and permissions'
    run_artisan db:seed --force
fi

printf '\n%sEnd to end database %s rebuilt.%s\n' "${GREEN}${BOLD}" "$ACTUAL" "${RESET}"
