#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - tests for scripts/reset-test-db.sh
#
# The reset script is the only thing standing between a mistyped command and the
# destruction of local data, and its whole value is in what it refuses. Those
# refusals are asserted here rather than trusted, because a guard that is never
# exercised is a comment.
#
# None of these tests wipes anything. The two refusals are proven by pointing the
# script at the wrong target and observing that it stops *before* migrating: the
# development database is never the subject of a migration in this file, and the
# script's own checks are what prevent it. A test that proved isolation by
# destroying the development database would be the bug.
#
# Usage, from the project root:
#     ./scripts/test-reset-test-db.sh
# ---------------------------------------------------------------------------
set -uo pipefail

cd "$(dirname "$0")/.."

SCRIPT="scripts/reset-test-db.sh"
FAILURES=0
CHECKS=0

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

pass() {
    CHECKS=$((CHECKS + 1))
    printf '    %sok%s   %s\n' "${GREEN}" "${RESET}" "$1"
}

fail() {
    CHECKS=$((CHECKS + 1))
    FAILURES=$((FAILURES + 1))
    printf '    %sFAIL%s %s\n' "${RED}" "${RESET}" "$1"
    if [ -n "${2:-}" ]; then
        printf '%s\n' "$2" | sed 's/^/         /'
    fi
}

heading() {
    printf '\n%s%s%s\n' "${BOLD}" "$1" "${RESET}"
}

# How many tables the database a string points at currently has. Used to prove
# that a refused run left its target untouched.
table_count() {
    local database="$1"

    docker compose exec -T postgres psql -U postgres -d "$database" -tAc \
        "select count(*) from information_schema.tables where table_schema='public';" 2>/dev/null \
        | tr -d '[:space:]'
}

# --- Case A: the correct configuration proceeds -----------------------------

heading 'A. the real test database is rebuilt'

DEVELOPMENT_TABLES_BEFORE="$(table_count consultora_dh)"

if output="$(./"$SCRIPT" --force 2>&1)"; then
    pass 'the script completed against the testing connection'

    if printf '%s' "$output" | grep -q 'current_database() is consultora_dh_test'; then
        pass 'it verified PostgreSQL was on consultora_dh_test'
    else
        fail 'it did not report verifying the test database' "$output"
    fi

    if printf '%s' "$output" | grep -q 'DONE'; then
        pass 'it ran the migrations'
    else
        fail 'no migration appears to have run' "$output"
    fi

    TEST_TABLES_AFTER="$(table_count consultora_dh_test)"
    if [ "${TEST_TABLES_AFTER:-0}" -gt 10 ]; then
        pass "the test database has its schema back ($TEST_TABLES_AFTER tables)"
    else
        fail "the test database has only ${TEST_TABLES_AFTER:-0} tables"
    fi
else
    fail 'the script refused the correct configuration' "$output"
fi

DEVELOPMENT_TABLES_AFTER="$(table_count consultora_dh)"

if [ "$DEVELOPMENT_TABLES_BEFORE" = "$DEVELOPMENT_TABLES_AFTER" ]; then
    pass "the development database was untouched ($DEVELOPMENT_TABLES_AFTER tables before and after)"
else
    fail 'the development database changed during a test-database reset' \
        "before: $DEVELOPMENT_TABLES_BEFORE, after: $DEVELOPMENT_TABLES_AFTER"
fi

# --- Case B: a development connection in the environment is refused ---------

heading 'B. DB_CONNECTION=pgsql is refused before any migration'

DEVELOPMENT_TABLES_BEFORE="$(table_count consultora_dh)"

if output="$(DB_CONNECTION=pgsql ./"$SCRIPT" --force 2>&1)"; then
    fail 'the script ran with DB_CONNECTION=pgsql in the environment' "$output"
else
    pass 'the script exited non-zero'

    if printf '%s' "$output" | grep -q 'Check 0 failed'; then
        pass 'it named the failing check'
    else
        fail 'it did not say which check failed' "$output"
    fi

    if printf '%s' "$output" | grep -q 'No table was dropped and no migration was run'; then
        pass 'it said plainly that nothing was migrated'
    else
        fail 'it did not state that nothing was migrated' "$output"
    fi

    # The strongest assertion available: no migration output at all.
    if printf '%s' "$output" | grep -qE 'Running migrations|DONE|Dropping all tables'; then
        fail 'migration output appeared despite the refusal' "$output"
    else
        pass 'no migration output at all'
    fi
fi

DEVELOPMENT_TABLES_AFTER="$(table_count consultora_dh)"

if [ "$DEVELOPMENT_TABLES_BEFORE" = "$DEVELOPMENT_TABLES_AFTER" ]; then
    pass 'the development database was not migrated'
else
    fail 'the development database changed' "before: $DEVELOPMENT_TABLES_BEFORE, after: $DEVELOPMENT_TABLES_AFTER"
fi

# --- Case C: the test database being the development database is refused ----

heading 'C. DB_TEST_DATABASE pointing at the development database is refused'

DEVELOPMENT_ROWS_BEFORE="$(docker compose exec -T postgres psql -U postgres -d consultora_dh -tAc \
    "select count(*) from clients;" 2>/dev/null | tr -d '[:space:]')"

if output="$(DB_TEST_DATABASE=consultora_dh ./"$SCRIPT" --force 2>&1)"; then
    fail 'the script ran with the test database set to the development database' "$output"
else
    pass 'the script exited non-zero'

    # Check 3 is the one that matters: the test database *is* the development one.
    if printf '%s' "$output" | grep -q 'Check 3 failed'; then
        pass 'it named check 3: the test database is the development database'
    else
        fail 'it did not fail on check 3' "$output"
    fi

    if printf '%s' "$output" | grep -qE 'Running migrations|DONE|Dropping all tables'; then
        fail 'migration output appeared despite the refusal' "$output"
    else
        pass 'no migration output at all'
    fi
fi

DEVELOPMENT_ROWS_AFTER="$(docker compose exec -T postgres psql -U postgres -d consultora_dh -tAc \
    "select count(*) from clients;" 2>/dev/null | tr -d '[:space:]')"

if [ "$DEVELOPMENT_ROWS_BEFORE" = "$DEVELOPMENT_ROWS_AFTER" ]; then
    pass "the development client rows are intact ($DEVELOPMENT_ROWS_AFTER)"
else
    fail 'the development data changed' "before: $DEVELOPMENT_ROWS_BEFORE, after: $DEVELOPMENT_ROWS_AFTER"
fi

# --- No credential ever appears in the output --------------------------------

heading 'D. refusals print no credentials'

if output="$(DB_CONNECTION=pgsql ./"$SCRIPT" --force 2>&1)"; then
    fail 'unexpected success while collecting output' "$output"
else
    if printf '%s' "$output" | grep -qiE "$(sed -n 's/^DB_PASSWORD=//p' .env 2>/dev/null | head -1)"; then
        fail 'a database password appeared in the refusal output' "$output"
    else
        pass 'no database password in the output'
    fi

    if printf '%s' "$output" | grep -qE 'pgsql://|postgres://|password='; then
        fail 'a connection string appeared in the refusal output' "$output"
    else
        pass 'no connection string in the output'
    fi
fi

# --- Summary ----------------------------------------------------------------

printf '\n'

if [ "$FAILURES" -eq 0 ]; then
    printf '%s%s checks passed.%s\n' "${GREEN}${BOLD}" "$CHECKS" "${RESET}"
    exit 0
fi

printf '%s%s of %s checks failed.%s\n' "${RED}${BOLD}" "$FAILURES" "$CHECKS" "${RESET}"
exit 1
