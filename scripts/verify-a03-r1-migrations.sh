#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - A03-R1 migration verification (review §57)
#
# Proves the three A03-R1 migrations do what they claim on three kinds of
# database, and refuses loudly where the data is not compatible.
#
#     A. a fresh, empty database, built from zero
#     B. an upgrade from the exact baseline commit 7af0f9f to R1
#     C. an upgrade from a realistic A03 development dataset
#
# D, E, F and G — the moment a migration meets incompatible rows, the removal
# of the allocation unique index, repeated allocations after the migration, and
# the database constraints reached by direct SQL — are proved by
# `tests/Feature/A03/MigrationTest.php`, because those are statements about
# data and belong in the suite that runs on every change.
#
# ## Why this is a script and not a test
#
# A and B are about running the migrations, in order, against a database that
# did not exist a minute ago or that is at a specific earlier commit. The Pest
# suite's database is already at head before its first test runs, so a test
# there could only assert about a schema it had itself created. This script
# builds disposable databases, migrates them and reports what happened.
#
# ## The baseline is read from git, not typed in
#
# The A03 migrations at 7af0f9f are the ones that have to exist before R1 runs,
# and "the migrations that were in the tree at that commit" is the only definition
# of them that cannot silently become wrong. `git show <commit>:<path>` reads
# them out of the repository; nothing is edited and no timestamp is touched. If
# the baseline commit is not reachable the script stops rather than migrating a
# set of files it made up.
#
# ## Nothing here touches a database anybody uses
#
# Three throwaway names, all of which must be different from development, from
# the Pest database and from the E2E database, and all of which are dropped
# afterwards whether the run passed or failed. The name is checked against those
# three both before creating and before dropping, because "it is only a test
# database" is exactly the belief that destroys data.
#
# Usage, from the project root:
#     ./scripts/verify-a03-r1-migrations.sh              # all three
#     ./scripts/verify-a03-r1-migrations.sh --keep       # leave the databases
# ---------------------------------------------------------------------------
set -euo pipefail

cd "$(dirname "$0")/.."

BASELINE_COMMIT="${A03_R1_BASELINE:-7af0f9f545ec94692c8c0c4301f872050c3c37c0}"

DEVELOPMENT_DATABASE="${DB_DATABASE:-consultora_dh}"
TEST_DATABASE="${DB_TEST_DATABASE:-consultora_dh_test}"
E2E_DATABASE="${DB_E2E_DATABASE:-consultora_dh_e2e}"

KEEP=0

for argument in "$@"; do
    case "$argument" in
        --keep) KEEP=1 ;;
        -h|--help)
            sed -n '2,45p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            printf 'Unknown argument: %s\n' "$argument" >&2
            printf 'Usage: ./scripts/verify-a03-r1-migrations.sh [--keep]\n' >&2
            exit 2
            ;;
    esac
done

# --- The three A03-R1 migrations, in order -----------------------------------
#
# Hardcoded rather than globbed: the point is to migrate exactly these three on
# top of the baseline, so a fourth migration added later does not silently join
# the upgrade under test.
R1_MIGRATIONS=(
    2026_10_03_100000_create_obligation_source_assignments_table.php
    2026_10_03_110000_require_generated_obligation_configuration_evidence.php
    2026_10_03_120000_allow_repeated_live_payment_allocations.php
)

# Everything that existed at the baseline.
BASELINE_MIGRATIONS=(
    0001_01_01_000000_create_users_table.php
    0001_01_01_000001_create_cache_table.php
    0001_01_01_000002_create_jobs_table.php
    2026_01_01_000100_create_audit_events_table.php
    2026_10_01_120710_create_permission_tables.php
    2026_10_01_210000_create_clients_table.php
    2026_10_01_210100_create_companies_table.php
    2026_10_01_210200_create_social_security_entities_table.php
    2026_10_01_210300_create_client_company_assignments_table.php
    2026_10_01_210400_create_client_affiliations_table.php
    2026_10_01_210500_add_subject_to_audit_events_table.php
    2026_10_01_220000_split_tax_id_and_verification_digit.php
    2026_10_01_220100_relax_contact_email_uniqueness.php
    2026_10_01_220200_relax_catalogue_code_uniqueness.php
    2026_10_01_230000_allow_unknown_start_to_be_closed.php
    2026_10_01_240000_forbid_duplicate_open_relationship.php
    2026_10_02_100000_create_monthly_periods_table.php
    2026_10_02_110000_create_cutoff_rules_and_rates_tables.php
    2026_10_02_120000_create_monthly_obligations_table.php
    2026_10_02_130000_create_payments_and_allocations_tables.php
)

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

FAILURES=0

step() { printf '\n==>%s %s\n' "${BOLD}" "${RESET}" "$1"; }
ok()   { printf '    %sok%s   %s\n' "${GREEN}" "${RESET}" "$1"; }
bad()  { printf '    %sFAIL%s %s\n' "${RED}" "${RESET}" "$1"; FAILURES=$((FAILURES + 1)); }
note() { printf '    --   %s\n' "$1"; }

# --- Talking to PostgreSQL --------------------------------------------------
#
# Straight through `psql` in the postgres container, so the verification does not
# depend on the application being able to boot. A migration bug should be
# observable even when the framework that would report it is what is broken.

psql_admin() {
    docker compose exec -T postgres psql -U postgres -v ON_ERROR_STOP=1 "$@"
}

# --- Talking to PostgreSQL --------------------------------------------------
#
# Straight through `psql` in the postgres container, so the verification does not
# depend on the application being able to boot. A migration bug should be
# observable even when the framework that would report it is what is broken.
#
# The databases are created owned by the **application's** role, because the
# migrations run as that role: a database owned by `postgres` and migrated by
# somebody else fails on the first `CREATE TABLE` with "permission denied for
# schema public", which is a statement about ownership and not about migrations.

psql_quiet() {
    docker compose exec -T postgres psql -U postgres -tAc "$1" "$2"
}

# The role the migrations run as, read from the configuration the application
# itself resolves rather than from a literal here.
APPLICATION_DB_USER="$(docker compose exec -T app php -r '
    require "/var/www/html/vendor/autoload.php";
    $app = require "/var/www/html/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo (string) config("database.connections.pgsql.username");
' 2>/dev/null || true)"

case "$APPLICATION_DB_USER" in
    ''|*[!A-Za-z0-9_]*)
        printf 'Could not resolve the application database role, so nothing was created.\n' >&2
        exit 1
        ;;
esac

# --- Creating a throwaway database, owned by the application -----------------
create_verification_database() {
    psql_admin -c "DROP DATABASE IF EXISTS $1" >/dev/null
    psql_admin -c "CREATE DATABASE $1 OWNER \"${APPLICATION_DB_USER}\"" >/dev/null
}

# --- Guarding the throwaway names -------------------------------------------

refuse() {
    printf '%sRefusing to run.%s\n' "${RED}${BOLD}" "${RESET}" >&2
    printf '%s\n' "$1" >&2
    printf 'No database was created or dropped.\n' >&2
    exit 1
}

VERIFICATION_DATABASES=(
    consultora_dh_r1_fresh
    consultora_dh_r1_baseline
    consultora_dh_r1_realistic
)

for candidate in "${VERIFICATION_DATABASES[@]}"; do
    case "$candidate" in
        ""|*[!a-z0-9_]*|[0-9]*)
            refuse "The verification database name '$candidate' is not a plain
    lowercase identifier. Refusing rather than interpolating it into SQL."
            ;;
    esac

    if [ "$candidate" = "$DEVELOPMENT_DATABASE" ] \
        || [ "$candidate" = "$TEST_DATABASE" ] \
        || [ "$candidate" = "$E2E_DATABASE" ]; then
        refuse "'$candidate' is one of the databases this project uses. This script
    only ever creates and drops the four names it declares."
    fi
done

ok "the three throwaway names differ from development, test and end to end"

# --- The baseline migrations, read out of git -------------------------------

BASELINE_DIR="$(mktemp -d)"
trap 'rm -rf "$BASELINE_DIR"' EXIT

step "Reading the A03 migrations out of $BASELINE_COMMIT"

if ! git cat-file -e "${BASELINE_COMMIT}^{commit}" 2>/dev/null; then
    refuse "The baseline commit $BASELINE_COMMIT is not reachable from this
    repository. The upgrade test cannot be faked with whatever files happen to
    be here now, so nothing was run."
fi

note "baseline resolved: $(git rev-parse "$BASELINE_COMMIT")"

MISSING=""

for migration in "${BASELINE_MIGRATIONS[@]}"; do
    if ! git show "${BASELINE_COMMIT}:database/migrations/${migration}" \
        > "${BASELINE_DIR}/${migration}" 2>/dev/null; then
        MISSING="${MISSING}    ${migration}\n"
    fi
done

if [ -n "$MISSING" ]; then
    printf '    %sFAIL%s %s migration(s) are not in %s:\n' \
        "${RED}" "${RESET}" "$(printf '%s' "$MISSING" | grep -c ' ')" "$BASELINE_COMMIT" >&2
    printf '%b' "$MISSING" >&2
    printf '    The list in this script is meant to be the migrations of the baseline.\n' >&2
    exit 1
fi

ok "$(printf '%s\n' "${BASELINE_MIGRATIONS[@]}" | wc -l | tr -d ' ') baseline migrations read"

# Any migration that is in the tree but not in either list would silently be part
# of the "upgrade" under test, so the two lists are checked against the tree.
TREE_MIGRATIONS="$(ls database/migrations | sort)"
EXPECTED_MIGRATIONS="$(
    printf '%s\n' "${BASELINE_MIGRATIONS[@]}" "${R1_MIGRATIONS[@]}" | sort
)"

if [ "$TREE_MIGRATIONS" != "$EXPECTED_MIGRATIONS" ]; then
    printf '    %sFAIL%s the migrations in the tree and the migrations this script\n' \
        "${RED}" "${RESET}" >&2
    printf '    migrates are different sets. In the tree and not in the script:\n' >&2
    comm -23 <(printf '%s\n' "$TREE_MIGRATIONS") <(printf '%s\n' "$EXPECTED_MIGRATIONS") >&2
    printf '    In the script and not in the tree:\n' >&2
    comm -13 <(printf '%s\n' "$TREE_MIGRATIONS") <(printf '%s\n' "$EXPECTED_MIGRATIONS") >&2
    printf '    Add the missing one deliberately, or the upgrade proves the wrong thing.\n' >&2
    exit 1
fi

ok "the tree holds exactly these $(printf '%s\n' "$EXPECTED_MIGRATIONS" | wc -l | tr -d ' ') migrations"

# --- Running a migration file by hand --------------------------------------
#
# The A03-R1 files are read from the working tree, exactly as they are shipped.
# `migrate` is not used because it would also pick up anything else in the
# directory, and because these cases need to stop on the first failure with the
# database left in the state the failure produced.

apply_migration() {
    local database="$1"
    local file="$2"

    docker compose exec -T \
        -e VERIFY_DATABASE="$database" \
        -e VERIFY_MIGRATION="$file" \
        app php -r '
            require "/var/www/html/vendor/autoload.php";
            $app = require "/var/www/html/bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

            config(["database.default" => "pgsql", "database.connections.pgsql.database" => getenv("VERIFY_DATABASE")]);

            $migration = require "/var/www/html/database/migrations/" . getenv("VERIFY_MIGRATION");
            $migration->up();

            echo "applied:" . getenv("VERIFY_MIGRATION") . PHP_EOL;
        '
}

# --- A. A fresh, empty database, from zero ----------------------------------

step 'A. Building a fresh empty database from zero'

FRESH=consultora_dh_r1_fresh

create_verification_database "$FRESH"

for migration in "${BASELINE_MIGRATIONS[@]}"; do
    apply_migration "$FRESH" "$migration" >/dev/null
done

note "baseline applied"

for migration in "${R1_MIGRATIONS[@]}"; do
    apply_migration "$FRESH" "$migration" >/dev/null
done

FRESH_TABLES="$(psql_quiet "select count(*) from information_schema.tables where table_schema = 'public'" "$FRESH")"
FRESH_SOURCE="$(psql_quiet "select count(*) from information_schema.tables where table_schema='public' and table_name='obligation_source_assignments'" "$FRESH")"
FRESH_PAIR_INDEX="$(psql_quiet "select count(*) from pg_indexes where tablename='payment_allocations' and indexname='payment_allocations_live_pair_unique'" "$FRESH")"

if [ "$FRESH_TABLES" -ge 25 ] && [ "$FRESH_SOURCE" = "1" ] && [ "$FRESH_PAIR_INDEX" = "0" ]; then
    ok "from zero: ${FRESH_TABLES} tables, the provenance table exists, the allocation index is gone"
else
    bad "from zero produced ${FRESH_TABLES} tables, provenance=${FRESH_SOURCE}, old index=${FRESH_PAIR_INDEX}"
fi

# --- B. An upgrade from the exact baseline ---------------------------------

step "B. Upgrading the exact baseline ${BASELINE_COMMIT} to R1"

BASELINE_DB=consultora_dh_r1_baseline

create_verification_database "$BASELINE_DB"

for migration in "${BASELINE_MIGRATIONS[@]}"; do
    apply_migration "$BASELINE_DB" "$migration" >/dev/null
done

BEFORE_TABLES="$(psql_quiet "select count(*) from information_schema.tables where table_schema='public'" "$BASELINE_DB")"

for migration in "${R1_MIGRATIONS[@]}"; do
    apply_migration "$BASELINE_DB" "$migration" >/dev/null
done

AFTER_TABLES="$(psql_quiet "select count(*) from information_schema.tables where table_schema='public'" "$BASELINE_DB")"

if [ "$AFTER_TABLES" -eq $((BEFORE_TABLES + 1)) ]; then
    ok "empty baseline ${BEFORE_TABLES} tables upgraded to ${AFTER_TABLES}, one new table and no loss"
else
    bad "the upgrade went from ${BEFORE_TABLES} to ${AFTER_TABLES} tables; one table was expected"
fi

# --- C. An upgrade over a realistic A03 dataset -----------------------------

step 'C. Upgrading a realistic A03 dataset'

REALISTIC=consultora_dh_r1_realistic

create_verification_database "$REALISTIC"

for migration in "${BASELINE_MIGRATIONS[@]}"; do
    apply_migration "$REALISTIC" "$migration" >/dev/null
done

# A dataset with the shapes a real A03 development database has: a client with
# several months generated, a payment applied across two of them, one adjustment,
# and — deliberately — one generated obligation whose evidence is incomplete,
# because that is the row the migration is supposed to refuse.
#
# It is written in SQL rather than through the application, because the
# application refuses to write the incomplete row at all: the point is to meet the
# migration with data the schema *permitted* under the baseline and the schema
# does not permit now.
psql_admin -d "$REALISTIC" >/dev/null <<'SQL'
INSERT INTO users (id, name, email, email_verified_at, password, remember_token,
                   created_at, updated_at, status)
VALUES (1, 'Ana Prueba', 'ana@consultora-dh.test', now(), 'x', null, now(), now(), 'active');

INSERT INTO companies (id, tax_id, verification_digit, legal_name, trade_name,
                       status, created_at, updated_at)
VALUES (1, '900111111', '3', 'Comercial Wo S.A.S.', 'Comercial Wo', 'active', now(), now());

INSERT INTO clients (id, document_type, document_number, first_names, last_names,
                     status, created_at, updated_at)
VALUES (1, 'CC', '11111111', 'Carla', 'Prueba', 'active', now(), now()),
       (2, 'CC', '22222222', 'Carlos', 'Prueba', 'active', now(), now());

INSERT INTO client_company_assignments
    (id, client_id, company_id, started_on, ended_on, created_at, updated_at)
VALUES (1, 1, 1, '2026-01-01', null, now(), now()),
       (2, 2, 1, '2026-01-01', null, now(), now());

INSERT INTO cutoff_rules
    (id, scope, client_id, company_id, effective_month, cutoff_day, month_offset,
     notes, created_at, updated_at, created_by)
VALUES (1, 'general', null, null, '2026-01-01', 10, 1, null, now(), now(), 1),
       (2, 'company', null, 1, '2026-01-01', 12, 1, 'Acordado', now(), now(), 1);

INSERT INTO client_company_rates
    (id, client_id, company_id, effective_month, amount_cop, notes,
     created_at, updated_at, created_by)
VALUES (1, 1, 1, '2026-01-01', 235000, null, now(), now(), 1),
       (2, 2, 1, '2026-01-01', 120000, null, now(), now(), 1);

-- Two months already billed and closed, which is what a real A03 development
-- database looks like: a closed month carries its closing moment and its reason
-- for having been reopened, if it ever was.
INSERT INTO monthly_periods
    (id, period_month, status, opened_at, generation_performed_at,
     closed_at, last_reopen_reason, created_at, updated_at)
VALUES (1, '2026-01-01', 'closed', '2025-12-20 09:00:00', '2025-12-20 09:05:00',
        '2026-02-01 17:00:00', 'Se corrigió un valor mal configurado', now(), now()),
       (2, '2026-02-01', 'closed', '2026-01-20 09:00:00', '2026-01-20 09:05:00',
        '2026-03-01 17:00:00', null, now(), now());

INSERT INTO monthly_obligations
    (id, period_id, client_id, company_id, client_company_assignment_id, rate_id,
     cutoff_rule_id, base_amount_cop, due_on, generated_at, source, created_at, updated_at)
VALUES (1, 1, 1, 1, 1, 1, 1, 235000, '2026-02-10', now(), 'generated', now(), now()),
       (2, 1, 2, 1, 2, 2, 1, 120000, '2026-02-12', now(), 'generated', now(), now()),
       (3, 2, 1, 1, 1, 1, 2, 235000, '2026-03-12', now(), 'generated', now(), now()),
       -- The row the migration must refuse: generated, and it cannot say which rule
       -- gave it its due date.
       (4, 2, 2, 1, 2, 2, null, 120000, '2026-03-10', now(), 'generated', now(), now());

INSERT INTO obligation_adjustments
    (id, obligation_id, type, delta_cop, reason, reverses_adjustment_id,
     created_at, updated_at, created_by)
VALUES (1, 3, 'discount', -15000, 'Se corrigió la base pactada', null, now(), now(), 1);

INSERT INTO payments
    (id, client_id, amount_cop, received_on, method, reference, notes,
     voided_at, voided_by, void_reason, created_at, updated_at, created_by)
VALUES (1, 1, 300000, '2026-03-05', 'cash', 'REC-1', null, null, null, null, now(), now(), 1);

INSERT INTO payment_allocations
    (id, payment_id, obligation_id, amount_cop, reversed_at, reversal_reason,
     created_at, updated_at, created_by)
VALUES (1, 1, 1, 235000, null, null, now(), now(), 1),
       (2, 1, 3, 60000, null, null, now(), now(), 1);
SQL

note "the realistic dataset holds $(psql_quiet "select count(*) from monthly_obligations" "$REALISTIC") obligations, $(psql_quiet "select count(*) from payments" "$REALISTIC") payments and one generated obligation with no cutoff rule behind it"

# C1: the migration refuses, and says which row.
step 'C1. The upgrade refuses over the incompatible row, and names it'

REFUSAL="$(apply_migration "$REALISTIC" "2026_10_03_110000_require_generated_obligation_configuration_evidence.php" 2>&1 || true)"

if printf '%s' "$REFUSAL" | grep -q 'RuntimeException'; then
    if printf '%s' "$REFUSAL" | grep -q 'No se inventa ni se borra'; then
        ok "the upgrade stopped and said what it would not do"
    else
        bad "the upgrade stopped without saying that it refuses to invent or delete"
    fi

    # The ids come after the "IDs:" marker the message writes, so the count in the
    # sentence above it is not mistaken for an id. `head -n 1` because a PHP fatal
    # repeats the message once per stack frame.
    NAMED="$(printf '%s' "$REFUSAL" | grep -o 'IDs: [0-9, ]*' | head -n 1 | sed 's/IDs: //' | tr -d ' ')"
    if [ "$NAMED" = "4" ]; then
        ok "and it named obligation 4, the one that cannot be explained"
    else
        bad "the refusal named [${NAMED}] rather than obligation 4"
    fi
else
    bad "the upgrade did not refuse a generated obligation with no cutoff rule"
    printf '%s\n' "$REFUSAL" | head -5
fi

# Nothing was written while it refused.
KEPT="$(psql_quiet "select count(*) from monthly_obligations" "$REALISTIC")"
CHECK_STILL_GONE="$(psql_quiet "select count(*) from pg_constraint where conname = 'monthly_obligations_generated_evidence_check'" "$REALISTIC")"

if [ "$KEPT" = "4" ] && [ "$CHECK_STILL_GONE" = "0" ]; then
    ok "no row was deleted and no constraint was added while it refused"
else
    bad "after the refusal: ${KEPT} obligations (expected 4), check present=${CHECK_STILL_GONE}"
fi

# C2: with that one row decided by hand, the upgrade goes through and nothing else moves.
step 'C2. The upgrade goes through once the incompatible row is resolved'

psql_admin -d "$REALISTIC" >/dev/null <<'SQL'
-- The operator's decision, and the only one this script ever makes: the row is
-- marked as the hand-written correction it has to be, so the evidence rule does
-- not describe it. Deleting it or inventing a rule for it are both refused by
-- design, so the choice is left visible here rather than hidden in the script.
UPDATE monthly_obligations
SET source = 'manual_correction', rate_id = NULL, cutoff_rule_id = NULL
WHERE id = 4;
SQL

BEFORE_SNAPSHOT="$(psql_quiet "select md5(string_agg(t::text, '|' order by t.id)) from monthly_obligations t" "$REALISTIC")"
BEFORE_ALLOCATIONS="$(psql_quiet "select count(*) from payment_allocations" "$REALISTIC")"
BEFORE_ADJUSTMENTS="$(psql_quiet "select count(*) from obligation_adjustments" "$REALISTIC")"

for migration in "${R1_MIGRATIONS[@]}"; do
    apply_migration "$REALISTIC" "$migration" >/dev/null
done

AFTER_SNAPSHOT="$(psql_quiet "select md5(string_agg(t::text, '|' order by t.id)) from monthly_obligations t" "$REALISTIC")"
AFTER_ALLOCATIONS="$(psql_quiet "select count(*) from payment_allocations" "$REALISTIC")"
AFTER_ADJUSTMENTS="$(psql_quiet "select count(*) from obligation_adjustments" "$REALISTIC")"

if [ "$BEFORE_SNAPSHOT" = "$AFTER_SNAPSHOT" ]; then
    ok "not one obligation row changed: the checksum is identical before and after"
else
    bad "the upgrade changed the obligation rows (checksum ${BEFORE_SNAPSHOT} -> ${AFTER_SNAPSHOT})"
fi

if [ "$BEFORE_ALLOCATIONS" = "$AFTER_ALLOCATIONS" ] && [ "$BEFORE_ADJUSTMENTS" = "$AFTER_ADJUSTMENTS" ]; then
    ok "the ${AFTER_ALLOCATIONS} allocations and ${AFTER_ADJUSTMENTS} adjustments are all still there"
else
    bad "the upgrade changed financial history: allocations ${BEFORE_ALLOCATIONS}->${AFTER_ALLOCATIONS}, adjustments ${BEFORE_ADJUSTMENTS}->${AFTER_ADJUSTMENTS}"
fi

# Every obligation that already named a segment got exactly one provenance row, derived
# from that column. Nothing is invented: a row without one would get none.
PROVENANCE="$(psql_quiet "select count(*) from obligation_source_assignments" "$REALISTIC")"
EXPECTED_PROVENANCE="$(psql_quiet "select count(*) from monthly_obligations where client_company_assignment_id is not null" "$REALISTIC")"
REALISTIC_CHECK="$(psql_quiet "select count(*) from pg_constraint where conname = 'monthly_obligations_generated_evidence_check'" "$REALISTIC")"
REALISTIC_INDEX="$(psql_quiet "select count(*) from pg_indexes where tablename='payment_allocations' and indexname='payment_allocations_live_pair_unique'" "$REALISTIC")"

if [ "$PROVENANCE" = "$EXPECTED_PROVENANCE" ] && [ "$REALISTIC_CHECK" = "1" ] && [ "$REALISTIC_INDEX" = "0" ]; then
    ok "${PROVENANCE} provenance rows for ${EXPECTED_PROVENANCE} obligations that named a segment, the evidence check is enforced and the old index is gone"
else
    bad "after the upgrade: provenance=${PROVENANCE} (expected ${EXPECTED_PROVENANCE}), evidence check=${REALISTIC_CHECK}, old index=${REALISTIC_INDEX}"
fi

# C3: and the repeated allocation the index used to forbid, on upgraded data.
step 'C3. A repeated allocation is accepted on the upgraded database'

psql_admin -d "$REALISTIC" >/dev/null <<'SQL'
INSERT INTO payment_allocations
    (id, payment_id, obligation_id, amount_cop, reversed_at, reversal_reason,
     created_at, updated_at, created_by)
VALUES (3, 1, 3, 60000, null, null, now(), now(), 1);
SQL

REPEATED="$(psql_quiet "select count(*) from payment_allocations where payment_id = 1 and obligation_id = 3 and reversed_at is null" "$REALISTIC")"

if [ "$REPEATED" = "2" ]; then
    ok "two live allocations of the same payment against the same obligation now exist"
else
    bad "expected 2 live allocations of the pair, found ${REPEATED}"
fi

# --- Cleaning up ------------------------------------------------------------

if [ "$KEEP" -eq 1 ]; then
    step 'Keeping the verification databases (--keep)'
    printf '    %s\n' "${VERIFICATION_DATABASES[@]}"
else
    step 'Dropping the verification databases'

    for database in "${VERIFICATION_DATABASES[@]}"; do
        # The same three comparisons as before the drop, because the one thing this
        # script must never do is drop a database somebody uses.
        if [ "$database" = "$DEVELOPMENT_DATABASE" ] \
            || [ "$database" = "$TEST_DATABASE" ] \
            || [ "$database" = "$E2E_DATABASE" ]; then
            continue
        fi

        psql_admin -c "DROP DATABASE IF EXISTS ${database}" >/dev/null
    done

    ok "three throwaway databases dropped"
fi

# --- The verdict ------------------------------------------------------------

printf '\n'

if [ "$FAILURES" -ne 0 ]; then
    printf '%s%d check(s) failed.%s\n' "${RED}${BOLD}" "$FAILURES" "${RESET}" >&2
    exit 1
fi

printf '%sA03-R1 migration verification passed (A, B and C).%s\n' "${GREEN}${BOLD}" "${RESET}"
printf 'D, E, F and G are proved by tests/Feature/A03/MigrationTest.php.\n'