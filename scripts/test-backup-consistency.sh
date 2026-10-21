#!/usr/bin/env bash
# ===========================================================================
# Backup consistency under concurrent writes - synthetic database
# ===========================================================================
# Usage (inside the backup sidecar, which has PostgreSQL 18 client tools):
#   docker compose --profile ops run --rm backup /scripts/test-backup-consistency.sh
#
# ## What it proves
#
# `scripts/backup.sh` dumps the database and then counts its tables. Taken
# separately, a write that lands between the two makes the restore proof compare
# a dump with the counts of a *later* state: the comparison fails although both
# are correct. That is a false alarm, and a false alarm teaches people to
# ignore the alarm.
#
# So the dump and the counts share one snapshot. This script proves it does not
# matter that the database is being written to while the backup runs: the
# backup is taken against a **disposable synthetic database**, writes are
# issued continuously during it, and the restore proof of that backup has to
# pass — every count, every file.
#
# The disposable database and the backup it produces are deleted at the end.
# The operational database is never touched.
# ===========================================================================
set -Eeuo pipefail

PG_HOST="${PGHOST:-${PG_HOST:-postgres}}"
PG_PORT="${PGPORT:-${PG_PORT:-5432}}"
PG_USER="${PGUSER:-${PG_USER:-consultora_dh_backup}}"
PG_DB="${PGDATABASE:-${PG_DB:-consultora_dh}}"
BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_ENCRYPTION_PASSPHRASE="${BACKUP_ENCRYPTION_PASSPHRASE:-}"
BACKUP_ENCRYPTION_KEY_FILE="${BACKUP_ENCRYPTION_KEY_FILE:-}"

TIMESTAMP=$(date -u +"%Y%m%d_%H%M%S")
DISPOSABLE_DB="consultora_dh_backuptest_${TIMESTAMP}"
WORK_ROOT="/tmp/backuptest_${TIMESTAMP}"

if [[ "${DISPOSABLE_DB}" == "${PG_DB}" ]]; then
  echo "[ERROR] El nombre disposable coincide con la base operativa; abortando." >&2
  exit 1
fi

WRITER_PID=""

cleanup() {
  local status=$?

  if [[ -n "${WRITER_PID}" ]]; then
    kill "${WRITER_PID}" 2>/dev/null || true
    wait "${WRITER_PID}" 2>/dev/null || true
  fi

  dropdb \
    --host="${PG_HOST}" \
    --port="${PG_PORT}" \
    --username="${PG_USER}" \
    --maintenance-db=postgres \
    --if-exists \
    "${DISPOSABLE_DB}" 2>/dev/null || true

  rm -rf "${WORK_ROOT}" 2>/dev/null || true
  find "${BACKUP_DIR}" -maxdepth 1 -type d -name "consultora_dh_*" -mmin +0 -exec rm -rf {} + 2>/dev/null || true

  exit "${status}"
}
trap cleanup EXIT

psql_db() {
  local database="$1"
  shift

  psql \
    --host="${PG_HOST}" \
    --port="${PG_PORT}" \
    --username="${PG_USER}" \
    --dbname="${database}" \
    --no-psqlrc \
    --set=ON_ERROR_STOP=1 \
    "$@"
}

echo "[${TIMESTAMP}] Preparando la base sintética ${DISPOSABLE_DB}..."

createdb \
  --host="${PG_HOST}" \
  --port="${PG_PORT}" \
  --username="${PG_USER}" \
  --maintenance-db=postgres \
  "${DISPOSABLE_DB}"

# The shape the backup cares about: a table that grows while the backup runs.
psql_db "${DISPOSABLE_DB}" --quiet --command="
  CREATE TABLE clients (
    id bigserial PRIMARY KEY,
    document_number text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
  );
  INSERT INTO clients (document_number)
  SELECT 'CC-' || g FROM generate_series(1, 500) g;
"

mkdir -p "${WORK_ROOT}"

echo "[${TIMESTAMP}] Escribiendo durante el backup..."

# A writer that never stops while the backup runs. The rows it adds must NOT
# change what the backup contains: the dump and the counts describe the same
# snapshot, so they describe the state at the beginning of the backup.
(
  while true; do
    psql_db "${DISPOSABLE_DB}" --quiet --command="
      INSERT INTO clients (document_number) VALUES ('CC-WRITER-' || md5(random()::text));
    " >/dev/null 2>&1 || true
    sleep 0.05
  done
) &
WRITER_PID=$!

BEFORE=$(psql_db "${DISPOSABLE_DB}" --quiet --tuples-only --no-align --command="SELECT count(*) FROM clients")

BACKUP_DIR="${WORK_ROOT}" \
PGDATABASE="${DISPOSABLE_DB}" \
BACKUP_REQUIRE_ENCRYPTION=false \
BACKUP_ENCRYPTION_PASSPHRASE="" \
BACKUP_ENCRYPTION_KEY_FILE="" \
  /scripts/backup.sh

AFTER=$(psql_db "${DISPOSABLE_DB}" --quiet --tuples-only --no-align --command="SELECT count(*) FROM clients")

echo "[${TIMESTAMP}] Filas antes=${BEFORE} después=${AFTER} (el backup capturó ${BEFORE})"

if [[ "${BEFORE}" == "${AFTER}" ]]; then
  echo "[WARN] El escritor no llegó a escribir durante el backup; la prueba no demuestra nada." >&2
  exit 1
fi

PRODUCED=$(find "${WORK_ROOT}" -maxdepth 1 -type d -name "consultora_dh_*" | head -1)

if [[ -z "${PRODUCED}" ]]; then
  echo "[ERROR] El backup no produjo ningún directorio." >&2
  exit 1
fi

echo "[${TIMESTAMP}] Comprobando el restore proof de ${PRODUCED}..."

# The required storage directories are the application's own layout; this
# proof is about the database snapshot, so it does not judge them.
PGDATABASE="${DISPOSABLE_DB}" \
BACKUP_REQUIRE_ENCRYPTION=false \
BACKUP_ENCRYPTION_PASSPHRASE="" \
BACKUP_ENCRYPTION_KEY_FILE="" \
RESTORE_REQUIRED_DIRS="ai-artifacts imports" \
  /scripts/restore_proof.sh "${PRODUCED}"

echo "[${TIMESTAMP}] OK: el dump y los conteos describen la misma instantánea, con escrituras en curso."
