#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - tests for scripts/reset-e2e-db.sh
#
# The script drops a database. What makes it acceptable is that it refuses to drop
# the wrong one, so those refusals are asserted here rather than trusted: a guard
# nobody has ever run is a comment.
#
# None of these tests destroys anything. Each refusal is proven by pointing the
# script at a database that must be protected and observing that it stops BEFORE
# migrating. The development and Pest databases are never the subject of a
# migration in this file; proving isolation by wiping development would be the
# defect, not the proof.
#
# Usage, from the project root:
#     ./scripts/test-reset-e2e-db.sh
# ---------------------------------------------------------------------------
set -uo pipefail

cd "$(dirname "$0")/.."

SCRIPT="scripts/reset-e2e-db.sh"
E2E_DATABASE="${DB_E2E_DATABASE:-consultora_dh_e2e}"
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

# Row counts that must survive every test in this file.
fingerprint() {
    local database="$1"
    local tables clients companies roles

    tables="$(docker compose exec -T postgres psql -U postgres -d "$database" -tAc \
        "select count(*) from information_schema.tables where table_schema='public';" 2>/dev/null | tr -d '[:space:]')"

    # Not every database is guaranteed to have every table, so a missing one counts
    # as zero rather than aborting the check.
    for table in clients companies roles; do
        if [ "$table" = clients ] || [ "$table" = companies ]; then
            value="$(docker compose exec -T postgres psql -U postgres -d "$database" -tAc \
                "select count(*) from $table;" 2>/dev/null | tr -d '[:space:]')"
        else
            value="$(docker compose exec -T postgres psql -U postgres -d "$database" -tAc \
                "select count(*) from $table;" 2>/dev/null | tr -d '[:space:]')"
        fi

        case "$table" in
            clients) clients="${value:-0}" ;;
            companies) companies="${value:-0}" ;;
            roles) roles="${value:-0}" ;;
        esac
    done

    printf '%s|%s|%s|%s' "${tables:-0}" "${clients:-0}" "${companies:-0}" "${roles:-0}"
}

DEVELOPMENT_BEFORE="$(fingerprint consultora_dh)"
TEST_BEFORE="$(fingerprint consultora_dh_test)"

printf '%sdevelopment fingerprint: %s%s\n' "$BOLD" "$DEVELOPMENT_BEFORE" "$RESET"
printf '%sPest fingerprint:        %s%s\n' "$BOLD" "$TEST_BEFORE" "$RESET"

# --- A. the real E2E database is accepted -----------------------------------

heading "A. the real end to end database is rebuilt"

if output="$(./"$SCRIPT" --seed 2>&1)"; then
    pass 'the script completed'

    if printf '%s' "$output" | grep -q "current_database() is $E2E_DATABASE"; then
        pass "it verified PostgreSQL was on $E2E_DATABASE"
    else
        fail 'it did not report verifying the end to end database' "$output"
    fi

    # All six guards reported before anything destructive ran.
    for check in 'Check 1' 'Check 2' 'Check 3' 'Check 4' 'Check 5'; do
        if printf '%s' "$output" | grep -q "$check failed"; then
            fail "the accepted path reported $check as failed" "$output"
        fi
    done
    pass 'no guard reported a failure on the accepted path'

    if printf '%s' "$output" | grep -q 'DONE'; then
        pass 'it ran the migrations'
    else
        fail 'no migration appears to have run' "$output"
    fi

    ROLES="$(docker compose exec -T postgres psql -U postgres -d "$E2E_DATABASE" -tAc \
        'select count(*) from roles;' 2>/dev/null | tr -d '[:space:]')"

    if [ "${ROLES:-0}" -gt 0 ]; then
        pass "--seed loaded the role matrix ($ROLES roles)"
    else
        fail 'the role matrix was not seeded into the end to end database'
    fi
else
    fail 'the script refused the correct configuration' "$output"
fi

# --- B. the development database is refused ----------------------------------

heading 'B. a development database is refused'

if output="$(DB_DATABASE=consultora_dh ./"$SCRIPT" 2>&1)"; then
    fail 'the script ran with DB_DATABASE set to development' "$output"
else
    pass 'the script exited non-zero'

    if printf '%s' "$output" | grep -q 'Check 0 failed'; then
        pass 'it refused at check 0, before resolving anything'
    else
        fail 'it did not refuse at check 0' "$output"
    fi

    if printf '%s' "$output" | grep -qE 'Running migrations|DONE|Dropping all tables'; then
        fail 'migration output appeared despite the refusal' "$output"
    else
        pass 'no migration output at all'
    fi
fi

# --- C. the Pest test database is refused ------------------------------------

heading 'C. the Pest test database is refused'

if output="$(DB_DATABASE=consultora_dh_test ./"$SCRIPT" 2>&1)"; then
    fail 'the script ran with DB_DATABASE set to the Pest database' "$output"
else
    pass 'the script exited non-zero'

    if printf '%s' "$output" | grep -q 'Check 0 failed'; then
        pass 'it refused at check 0'
    else
        fail 'it did not refuse at check 0' "$output"
    fi
fi

# --- D. an unexpected database name is refused -------------------------------

heading 'D. an unexpected database name is refused'

if output="$(DB_E2E_DATABASE=consultora_dh_otra ./"$SCRIPT" 2>&1)"; then
    fail 'the script ran with an unexpected expected-name' "$output"
else
    pass 'the script exited non-zero'

    # With a mismatched expectation the name check must be what stops it, which is
    # the guard that makes a typo fail closed.
    if printf '%s' "$output" | grep -qE 'Check [02] failed'; then
        pass 'it refused on the name check'
    else
        fail 'it did not refuse on the name check' "$output"
    fi

    if printf '%s' "$output" | grep -qE 'Running migrations|DONE|Dropping all tables'; then
        fail 'migration output appeared despite the refusal' "$output"
    else
        pass 'no migration output at all'
    fi
fi

# --- E. the databases that must have survived, did ---------------------------

heading 'E. the protected databases are untouched'

DEVELOPMENT_AFTER="$(fingerprint consultora_dh)"
TEST_AFTER="$(fingerprint consultora_dh_test)"

if [ "$DEVELOPMENT_BEFORE" = "$DEVELOPMENT_AFTER" ]; then
    pass "the development database is unchanged ($DEVELOPMENT_AFTER)"
else
    fail 'the development database changed' "before: $DEVELOPMENT_BEFORE, after: $DEVELOPMENT_AFTER"
fi

if [ "$TEST_BEFORE" = "$TEST_AFTER" ]; then
    pass "the Pest database is unchanged ($TEST_AFTER)"
else
    fail 'the Pest database changed' "before: $TEST_BEFORE, after: $TEST_AFTER"
fi

# --- F. no credentials in the output -----------------------------------------

heading 'F. refusals print no credentials'

PASSWORD="$(sed -n 's/^DB_PASSWORD=//p' .env 2>/dev/null | head -1)"

if output="$(DB_DATABASE=consultora_dh ./"$SCRIPT" 2>&1)"; then
    fail 'unexpected success while collecting output' "$output"
else
    if [ -n "$PASSWORD" ] && printf '%s' "$output" | grep -qF "$PASSWORD"; then
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
