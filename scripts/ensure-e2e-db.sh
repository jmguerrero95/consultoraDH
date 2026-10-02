#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - create the end to end database on an EXISTING volume
#
# The PostgreSQL initialisation script in docker/postgres/init runs only when the
# data volume is created for the first time. A developer who already has a volume
# from before the end to end database existed needs another way to get it, and
# this is that way.
#
# ## What this may and may not do
#
# It may create the end to end database if it is missing, and hand it to the
# application role if the role does not own it yet. Nothing else.
#
# It never drops, recreates or truncates any database. In particular it never
# touches the development database, and it never runs a migration. The database
# it creates is empty and stays that way until something fills it deliberately.
#
# `DROP DATABASE IF EXISTS` is absent from this file on purpose. The destructive
# tool for this database is `scripts/reset-e2e-db.sh`, which is fail-closed and
# verifies where it is pointed before it drops anything. A setup script that could
# destroy data would be the wrong place to keep that ability.
#
# Usage, from the project root:
#     ./scripts/ensure-e2e-db.sh
# ---------------------------------------------------------------------------
set -euo pipefail

cd "$(dirname "$0")/.."

E2E_DATABASE="${DB_E2E_DATABASE:-consultora_dh_e2e}"
DEVELOPMENT_DATABASE="${DB_DATABASE:-consultora_dh}"
TEST_DATABASE="${DB_TEST_DATABASE:-consultora_dh_test}"
APP_USER="${DB_USERNAME:-consultora_dh_app}"
BOOTSTRAP_USER="${POSTGRES_USER:-postgres}"

# The same separation the initialisation script insists on. If these collide, the
# three suites share a database and every guard downstream is meaningless, so this
# is checked before anything is created.
if [ "$E2E_DATABASE" = "$DEVELOPMENT_DATABASE" ] || [ "$E2E_DATABASE" = "$TEST_DATABASE" ]; then
    printf 'Refusing to continue.\n' >&2
    printf 'DB_E2E_DATABASE (%s) must differ from DB_DATABASE (%s) and DB_TEST_DATABASE (%s).\n' \
        "$E2E_DATABASE" "$DEVELOPMENT_DATABASE" "$TEST_DATABASE" >&2
    printf 'No database was created.\n' >&2
    exit 1
fi

psql_admin() {
    docker compose exec -T postgres psql \
        --username "$BOOTSTRAP_USER" \
        --dbname postgres \
        --tuples-only \
        --no-align \
        --set ON_ERROR_STOP=1 \
        "$@"
}

# --- Is the database already there? -----------------------------------------

EXISTS="$(psql_admin --command "SELECT 1 FROM pg_database WHERE datname = '$E2E_DATABASE'" || true)"

if [ "$EXISTS" = "1" ]; then
    printf '==> The end to end database already exists: %s\n' "$E2E_DATABASE"

    # Created by an older run, or by hand: make sure the application role owns it,
    # because otherwise the migration that follows cannot create a single table.
    OWNER="$(psql_admin --command "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '$E2E_DATABASE'" || true)"

    if [ "$OWNER" != "$APP_USER" ]; then
        printf '==> Handing it to the application role (%s)\n' "$APP_USER"
        psql_admin --command "ALTER DATABASE \"$E2E_DATABASE\" OWNER TO \"$APP_USER\"" >/dev/null
    fi

    printf '==> Nothing else was done. No table was dropped and no migration was run.\n'
    exit 0
fi

# --- Create it ---------------------------------------------------------------

printf '==> Creating the end to end database: %s\n' "$E2E_DATABASE"

# The owner is the application role: NOSUPERUSER, NOCREATEDB, NOCREATEROLE. That is
# what a migration needs and nothing more. The bootstrap superuser is not given the
# database to use, only to create it.
psql_admin --command "CREATE DATABASE \"$E2E_DATABASE\" OWNER \"$APP_USER\"" >/dev/null

# Confirm the role really does lack the privileges it must never hold. A role that
# could create databases could create one named after development, and the guards in
# the reset script would then be checking a name rather than a database.
PRIVILEGES="$(psql_admin --command "SELECT rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = '$APP_USER'")"

if [ "$PRIVILEGES" != "f|f|f" ]; then
    printf 'Refusing to report success.\n' >&2
    printf 'The application role holds administrative privileges it must not have (superuser/createdb/createrole = %s).\n' "$PRIVILEGES" >&2
    printf 'The database %s was created and should be reviewed before it is used.\n' "$E2E_DATABASE" >&2
    exit 1
fi

printf '==> Done. Owned by %s, which holds no superuser, createdb or createrole privilege.\n' "$APP_USER"
printf '    Run ./scripts/reset-e2e-db.sh to migrate and seed it.\n'
