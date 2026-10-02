#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - PostgreSQL role bootstrap
#
# Runs ONCE, when the data volume is created for the first time, and only for
# the Consultora DH development volume.
#
# Two roles with clearly separated purposes:
#
#   * The BOOTSTRAP role (POSTGRES_USER, default `postgres`) is a PostgreSQL
#     superuser. The official image creates it, and this script uses it to do
#     administrative work. Laravel never connects with it.
#
#   * The APPLICATION role (DB_USERNAME, default `consultora_dh_app`) is the
#     only one Laravel uses. It is created as NOSUPERUSER, NOCREATEDB and
#     NOCREATEROLE, and becomes the owner of the Consultora DH databases, which
#     is everything a migration needs (create tables, alter them, create
#     indexes, insert seed data) and nothing more.
#
# Three databases, three purposes, and the separation is the point:
#
#   * development  (`DB_DATABASE`)         a developer's real work;
#   * unit tests   (`DB_TEST_DATABASE`)    the Pest suite;
#   * end to end   (`DB_E2E_DATABASE`)     the Playwright suite.
#
# The end to end database exists because the Playwright suite creates real
# records. While it ran against development it had to delete them afterwards by
# guessing which rows were its own, using `LIKE '%some digits%'` against
# document numbers and company names. A developer whose record happened to
# contain those digits would have had it deleted. A dedicated database removes
# the question: there is nothing to recognise, because nothing was written
# anywhere else.
#
# The script is idempotent: re-running it neither fails nor duplicates work.
# ---------------------------------------------------------------------------
set -euo pipefail

bootstrap_db="${POSTGRES_DB:-consultora_dh}"
app_db="${DB_DATABASE:-consultora_dh}"
test_db="${DB_TEST_DATABASE:-consultora_dh_test}"
e2e_db="${DB_E2E_DATABASE:-consultora_dh_e2e}"
app_user="${DB_USERNAME:-consultora_dh_app}"
app_password="${DB_PASSWORD:-}"

if [ -z "$app_password" ]; then
    echo "[consultora-dh] ERROR: DB_PASSWORD is not set; cannot create the application role." >&2
    exit 1
fi

# The three names must be three different databases. A collision here would mean
# one of the suites wipes the data of another, and the guard belongs at the point
# where the databases are created, not in each consumer.
if [ "$app_db" = "$test_db" ]; then
    echo "[consultora-dh] ERROR: DB_TEST_DATABASE must differ from DB_DATABASE." >&2
    exit 1
fi

if [ "$e2e_db" = "$app_db" ] || [ "$e2e_db" = "$test_db" ]; then
    echo "[consultora-dh] ERROR: DB_E2E_DATABASE ('${e2e_db}') must differ from both" >&2
    echo "[consultora-dh]        DB_DATABASE ('${app_db}') and DB_TEST_DATABASE ('${test_db}')." >&2
    exit 1
fi

# The application role must never be the bootstrap superuser.
if [ "$app_user" = "${POSTGRES_USER:-postgres}" ]; then
    echo "[consultora-dh] ERROR: the application role must not be the PostgreSQL superuser." >&2
    exit 1
fi

echo "[consultora-dh] creating the application role '${app_user}' (no superuser)..."

psql --username "$POSTGRES_USER" --dbname "$bootstrap_db" \
    --set ON_ERROR_STOP=1 \
    --set app_user="$app_user" \
    --set app_password="$app_password" \
    --set app_db="$app_db" \
    --set test_db="$test_db" \
    --set e2e_db="$e2e_db" <<'SQL'
-- Create the role without SUPERUSER, without CREATEDB and without CREATEROLE.
-- The password is passed as a psql variable and quoted by :'var', never
-- interpolated into the statement text.
SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT PASSWORD %L', :'app_user', :'app_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_user')\gexec

-- The application role owns its databases. Owning a database is what grants
-- CREATE on its public schema and allows migrations to run.
SELECT format('CREATE DATABASE %I OWNER %I', :'app_db', :'app_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'app_db')\gexec

SELECT format('CREATE DATABASE %I OWNER %I', :'test_db', :'app_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'test_db')\gexec

SELECT format('CREATE DATABASE %I OWNER %I', :'e2e_db', :'app_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'e2e_db')\gexec

-- If the database already existed without an owner, hand it over now.
SELECT format('ALTER DATABASE %I OWNER TO %I', :'app_db', :'app_user')\gexec
SELECT format('ALTER DATABASE %I OWNER TO %I', :'test_db', :'app_user')\gexec
SELECT format('ALTER DATABASE %I OWNER TO %I', :'e2e_db', :'app_user')\gexec

-- No further grants are needed for migrations. Since PostgreSQL 15 the public
-- schema is owned by the implicit `pg_database_owner` role, which resolves to
-- the owner of the database, so owning the database is sufficient to CREATE
-- tables in it. Any other role stays locked out of the schema.
SQL

echo "[consultora-dh] application role ready."
