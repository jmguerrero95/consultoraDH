#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - end to end test runner
#
# Creates two throwaway accounts, both with passwords generated for this run only,
# and hands them to Playwright through the environment. Nothing is written to disk
# and no credential is ever committed.
#
# Two accounts, because A02 has to prove that authorisation is enforced by the
# server and not merely hidden in the interface. A single administrator can show
# that a button is offered; it cannot show that a request without permission is
# refused. The second account holds the Read Only role, which may read the
# portfolio and change nothing.
#
# ## What is left behind, and what is not
#
# The suite runs against the development database, so it creates real records: two
# accounts, and clients, companies, social security entities, relationships,
# affiliations and audit rows for the flows it walks.
#
# All of it is removed afterwards. The records this run created are identified by
# STAMP, which every one of them carries in a field that belongs to it, and they are
# deleted children first by `consultora-dh:e2e-cleanup`. The script then verifies
# that nothing carrying the stamp is left, and fails the run if anything is. It also
# records the business table counts before the suite, so the state can be compared
# afterwards.
#
# Nothing broader is touched: no table is truncated, the schema is not rebuilt, and
# no record is deleted for having a name that merely looks like test data. A record
# from a previous run keeps its own stamp and is not this run's business.
#
# The account removal is defensive, because a leftover login is inert. The removal of
# business data is not: a silent failure there would leave the development database
# polluted while the run reported success, which is the exact failure this script
# exists to prevent.
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

# --- State of the business tables --------------------------------------------
#
# Recorded before the suite so it can be compared afterwards. These are counts of
# real records, used only to report what the run added and removed; nothing is ever
# truncated and no row is deleted on the strength of a count.
business_counts() {
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

# How many records carrying this run's stamp are still present, per table.
#
# This is the real isolation check. A count of zero in every table is what "this run
# left nothing behind" means, and it is asserted rather than assumed: a cleanup that
# silently did nothing would otherwise be indistinguishable from a clean run.
leftover_counts() {
    docker compose exec -T postgres psql -U postgres -d consultora_dh -tAc "
        select
            (select count(*) from clients where document_number like '%$STAMP%'),
            (select count(*) from companies where legal_name like '%$STAMP%' or tax_id like '%$STAMP%'),
            (select count(*) from social_security_entities where name like '%$STAMP%'),
            (select count(*) from client_company_assignments a
                join clients c on c.id = a.client_id where c.document_number like '%$STAMP%'),
            (select count(*) from client_affiliations f
                join clients c on c.id = f.client_id where c.document_number like '%$STAMP%');
    " 2>/dev/null | tr -d '[:space:]'
}

# Filled in before the suite runs, so the cleanup can compare against it.
BASELINE_COUNTS=""
BASELINE_LEFTOVER=""

# Set by the cleanup and read by the EXIT trap, which has to be able to turn a green
# suite into a failed one when the data did not come back.
CLEANUP_FAILED=0

cleanup() {
    local after

    echo "--- removing the records this run created (stamp $STAMP)"
    #
    # Not `|| true`. A business-data cleanup that fails must fail the run: the
    # alternative is a suite that reports success while the development database
    # keeps every record it made, which is the failure this script exists to prevent.
    if ! docker compose exec -T         -e E2E_CLEANUP_STAMP="$STAMP"         -e E2E_CLEANUP_ADMIN="$EMAIL"         -e E2E_CLEANUP_READER="$READER_EMAIL"         app php artisan consultora-dh:e2e-cleanup             --stamp="$STAMP" --email="$EMAIL" --email="$READER_EMAIL"; then
        echo "!!! The end to end data cleanup FAILED. The development database still has records from this run." >&2
        CLEANUP_FAILED=1
    fi

    echo "--- verifying that nothing from this run remains"
    after="$(leftover_counts || echo 'unavailable')"

    case "$after" in
        '0|0|0|0|0'|'')
            echo "    ok   no record carries the stamp $STAMP"
            ;;
        unavailable)
            echo "!!! Could not verify the cleanup. Refusing to report success." >&2
            CLEANUP_FAILED=1
            ;;
        *)
            echo "!!! Records from this run are still present: [${after//|/, }]" >&2
            echo "!!! The five numbers are clients, companies, entities, relationships, affiliations." >&2
            CLEANUP_FAILED=1
            ;;
    esac

    # Defensive only. The account removal above already handled both accounts, and a
    # failure here would mean a login survives a run, which is inert next to the
    # business data the cleanup command owns.
    echo "--- making sure the temporary accounts are gone"
    docker compose exec -T app php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        App\Models\User::query()
            ->whereIn("email", array_slice($argv, 1))
            ->delete();
    ' "$EMAIL" "$READER_EMAIL" >/dev/null 2>&1 || true

    local final
    final="$(business_counts || echo 'unavailable')"
    echo "--- business records before: ${BASELINE_COUNTS:-unavailable}"
    echo "--- business records after:  $final"
}

# The trap has to be able to change the outcome. An `EXIT` trap cannot do that by
# setting a variable, because the exit status has already been decided when it runs;
# so the cleanup calls `exit` itself when the data did not come back to baseline. The
# suite's own status is passed in and returned when the data is clean.
on_exit() {
    local status="${1:-0}"

    cleanup

    if [ "$CLEANUP_FAILED" -ne 0 ]; then
        # 1 is distinct from any Playwright status, and it means the run was green
        # but the database was not left as it was found.
        exit 1
    fi

    exit "$status"
}

# EXIT covers a normal finish, INT a Ctrl-C and TERM a kill. The same cleanup runs
# in all three, which is the point of a trap rather than a call at the end: a suite
# interrupted halfway still has records to remove.
trap 'on_exit $?' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

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

# Recorded before the suite, so the cleanup can print what the run added and removed.
# A run must be able to start from zero of its own stamp, and if a previous run of the
# same stamp left anything behind that is stated here rather than discovered later.
BASELINE_COUNTS="$(business_counts || echo 'unavailable')"
BASELINE_LEFTOVER="$(leftover_counts || echo 'unavailable')"
echo "--- business records before the suite: $BASELINE_COUNTS"
echo "--- records already carrying this stamp:  $BASELINE_LEFTOVER"

if [ "$BASELINE_LEFTOVER" != '0|0|0|0|0' ] && [ "$BASELINE_LEFTOVER" != 'unavailable' ]; then
    echo "!!! Records carrying the stamp $STAMP already exist. Removing them before starting." >&2
    docker compose exec -T app php artisan consultora-dh:e2e-cleanup --stamp="$STAMP" >/dev/null
fi

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

# The EXIT trap runs the cleanup and decides the final status from it, so there is
# nothing to do here but hand it the suite's result.
exit "$status"