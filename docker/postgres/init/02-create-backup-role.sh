#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - dedicated backup/restore role
#
# The application role is NOCREATEDB by design. `restore_proof.sh` needs to
# create and drop a disposable database, so it uses this separate role:
#
#   * LOGIN, CREATEDB, NOSUPERUSER, NOCREATEROLE;
#   * member of pg_read_all_data, so it can dump every table without owning it;
#   * credentials delivered only to the backup sidecar, never to Laravel.
#
# Runs only when the PostgreSQL data volume is created. For an existing volume
# run:
#
#   docker compose exec -e BACKUP_DB_PASSWORD="$BACKUP_DB_PASSWORD" \
#     postgres bash /docker-entrypoint-initdb.d/02-create-backup-role.sh
#
# The script is idempotent and refuses to reuse the application or bootstrap
# role.
# ---------------------------------------------------------------------------
set -euo pipefail

backup_user="${BACKUP_DB_USER:-consultora_dh_backup}"
backup_password="${BACKUP_DB_PASSWORD:-}"

if [ -z "$backup_password" ]; then
    echo "[consultora-dh] BACKUP_DB_PASSWORD is not set; skipping backup role creation." >&2
    echo "[consultora-dh] Set it in .env before enabling the backup service." >&2
    exit 0
fi

if [ "$backup_user" = "${POSTGRES_USER:-postgres}" ] || [ "$backup_user" = "${DB_USERNAME:-consultora_dh_app}" ]; then
    echo "[consultora-dh] ERROR: the backup role must differ from the bootstrap and application roles." >&2
    exit 1
fi

echo "[consultora-dh] creating the backup role '${backup_user}' (CREATEDB, no superuser)..."

psql --username "$POSTGRES_USER" --dbname "${POSTGRES_DB:-consultora_dh}" \
    --set ON_ERROR_STOP=1 \
    --set backup_user="$backup_user" \
    --set backup_password="$backup_password" <<'SQL'
SELECT format(
    'CREATE ROLE %I LOGIN NOSUPERUSER CREATEDB NOCREATEROLE PASSWORD %L',
    :'backup_user',
    :'backup_password'
)
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'backup_user')\gexec

-- Re-running the script rotates the password but never adds privileges.
SELECT format('ALTER ROLE %I PASSWORD %L', :'backup_user', :'backup_password')\gexec

-- Read-only access to every table and sequence, without ownership.
SELECT format('GRANT pg_read_all_data TO %I', :'backup_user')\gexec
SQL

echo "[consultora-dh] backup role ready."
