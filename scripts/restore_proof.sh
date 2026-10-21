#!/usr/bin/env bash
# ===========================================================================
# Restore Proof - verify a backup in a disposable database
# ===========================================================================
# Usage (inside the backup sidecar, which has PostgreSQL 18 client tools):
#   docker compose --profile ops run --rm backup /scripts/restore_proof.sh \
#     /backups/consultora_dh_20261011_020000
#
# It never restores over PGDATABASE. It creates consultora_dh_restore_<utc>,
# restores there, compares table counts with COUNTS.tsv, verifies storage file
# count and drops the disposable database/directory on exit.
#
# PGHOST, PGPORT, PGUSER, PGPASSWORD, PGDATABASE and the encryption variables
# are the same as scripts/backup.sh. PGUSER must have CREATEDB.
# ===========================================================================
set -Eeuo pipefail

BACKUP_PATH="${1:-}"
if [[ -z "${BACKUP_PATH}" || ! -d "${BACKUP_PATH}" ]]; then
  echo "Uso: $0 <backup_directory>" >&2
  exit 1
fi

PG_HOST="${PGHOST:-${PG_HOST:-postgres}}"
PG_PORT="${PGPORT:-${PG_PORT:-5432}}"
PG_USER="${PGUSER:-${PG_USER:-consultora_dh_backup}}"
PG_DB="${PGDATABASE:-${PG_DB:-consultora_dh}}"
BACKUP_ENCRYPTION_PASSPHRASE="${BACKUP_ENCRYPTION_PASSPHRASE:-}"
BACKUP_ENCRYPTION_KEY_FILE="${BACKUP_ENCRYPTION_KEY_FILE:-}"
# The private directories the application writes to.
#
# `clients` was in this list and the application never creates it: every copy
# the restore proof ran in a real deployment failed with "Directorio
# obligatorio no encontrado: clients", so the backup was never demonstrable and
# a release gate that asked for it could never pass.
#
# The directories are the ones the application really writes to, and only the
# ones the source actually has are required: a deployment that never wrote an
# attachment has no `ai-attachments` directory, and its absence is not a lost
# directory.
RESTORE_REQUIRED_DIRS="${RESTORE_REQUIRED_DIRS:-ai-artifacts ai-attachments document-work documents imports reports support-attachments}"
PRIVATE_STORAGE="${PRIVATE_STORAGE:-/var/www/html/storage/app/private}"

TIMESTAMP=$(date -u +"%Y%m%d_%H%M%S")
DISPOSABLE_DB="consultora_dh_restore_${TIMESTAMP}"
DISPOSABLE_DIR="/tmp/restore_${TIMESTAMP}"

if [[ "${DISPOSABLE_DB}" == "${PG_DB}" ]]; then
  echo "[ERROR] El nombre disposable coincide con la base operativa; abortando." >&2
  exit 1
fi

cleanup() {
  echo "[${TIMESTAMP}] Limpiando recursos disposable..."
  dropdb \
    --host="${PG_HOST}" \
    --port="${PG_PORT}" \
    --username="${PG_USER}" \
    --maintenance-db=postgres \
    --if-exists \
    "${DISPOSABLE_DB}" 2>/dev/null || true
  rm -rf "${DISPOSABLE_DIR}" 2>/dev/null || true
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

echo "[${TIMESTAMP}] Iniciando restore proof desde: ${BACKUP_PATH}"

# 1. Verify checksums before decrypting anything.
echo "[${TIMESTAMP}] Verificando checksums..."
(
  cd "${BACKUP_PATH}"
  sha256sum -c MANIFEST.sha256
)

mkdir -p "${DISPOSABLE_DIR}"

# 2. Decrypt payload when the backup was encrypted.
DUMP_PATH="${BACKUP_PATH}/postgres.dump"
STORAGE_PATH="${BACKUP_PATH}/private_storage.tar.gz"
COUNTS_PATH="${BACKUP_PATH}/COUNTS.tsv"

if [[ -f "${DUMP_PATH}.gpg" || -f "${STORAGE_PATH}.gpg" ]]; then
  if [[ -z "${BACKUP_ENCRYPTION_PASSPHRASE}" && ( -z "${BACKUP_ENCRYPTION_KEY_FILE}" || ! -f "${BACKUP_ENCRYPTION_KEY_FILE}" ) ]]; then
    echo "[ERROR] El backup está cifrado y no hay passphrase ni key file." >&2
    exit 1
  fi

  echo "[${TIMESTAMP}] Descifrando backup..."
  for file in postgres.dump private_storage.tar.gz COUNTS.tsv; do
    source="${BACKUP_PATH}/${file}.gpg"
    [[ -f "${source}" ]] || continue

    if [[ -n "${BACKUP_ENCRYPTION_PASSPHRASE}" ]]; then
      gpg --batch --yes --pinentry-mode loopback --decrypt \
        --passphrase-fd 3 \
        --output "${DISPOSABLE_DIR}/${file}" \
        "${source}" 3<<<"${BACKUP_ENCRYPTION_PASSPHRASE}"
    else
      gpg --batch --yes --pinentry-mode loopback --decrypt \
        --passphrase-file "${BACKUP_ENCRYPTION_KEY_FILE}" \
        --output "${DISPOSABLE_DIR}/${file}" \
        "${source}"
    fi
  done

  DUMP_PATH="${DISPOSABLE_DIR}/postgres.dump"
  STORAGE_PATH="${DISPOSABLE_DIR}/private_storage.tar.gz"
  COUNTS_PATH="${DISPOSABLE_DIR}/COUNTS.tsv"
fi

if [[ ! -f "${DUMP_PATH}" || ! -f "${STORAGE_PATH}" || ! -f "${COUNTS_PATH}" ]]; then
  echo "[ERROR] El backup no contiene postgres.dump, private_storage.tar.gz y COUNTS.tsv." >&2
  exit 1
fi

# 3. Create the disposable database as the backup role (CREATEDB), never the
#    application role and never the operational database.
echo "[${TIMESTAMP}] Creando BD disposable: ${DISPOSABLE_DB}"
createdb \
  --host="${PG_HOST}" \
  --port="${PG_PORT}" \
  --username="${PG_USER}" \
  --maintenance-db=postgres \
  "${DISPOSABLE_DB}"

# 4. Restore atomically.
echo "[${TIMESTAMP}] Restaurando PostgreSQL..."
pg_restore \
  --host="${PG_HOST}" \
  --port="${PG_PORT}" \
  --username="${PG_USER}" \
  --dbname="${DISPOSABLE_DB}" \
  --no-owner \
  --no-privileges \
  --single-transaction \
  --exit-on-error \
  "${DUMP_PATH}"

# 5. Compare every backed-up table count with the restored database.
echo "[${TIMESTAMP}] Verificando esquema y conteos..."
while IFS=$'\t' read -r table expected; do
  [[ -n "${table}" ]] || continue

  if [[ "${expected}" == "missing" ]]; then
    echo "  ${table}: missing in source (skipped)"
    continue
  fi

  exists=$(psql_db "${DISPOSABLE_DB}" --tuples-only --no-align \
    --command="SELECT to_regclass('public.${table}') IS NOT NULL")

  if [[ "${exists}" != "t" ]]; then
    echo "[ERROR] Tabla ausente en el restore: ${table}" >&2
    exit 1
  fi

  actual=$(psql_db "${DISPOSABLE_DB}" --tuples-only --no-align \
    --command="SELECT count(*) FROM public.${table}")

  if [[ "${actual}" != "${expected}" ]]; then
    echo "[ERROR] ${table}: backup=${expected}, restore=${actual}" >&2
    exit 1
  fi

  echo "  ${table}: ${actual} rows"
done < "${COUNTS_PATH}"

# 6. Extract and verify private storage.
echo "[${TIMESTAMP}] Verificando almacenamiento privado..."
EXTRACTED_STORAGE="${DISPOSABLE_DIR}/storage"
mkdir -p "${EXTRACTED_STORAGE}"
tar -xzf "${STORAGE_PATH}" -C "${EXTRACTED_STORAGE}"

EXPECTED_FILES=$(awk -F': ' '/^# StorageFiles:/ {print $2}' "${BACKUP_PATH}/MANIFEST.sha256")
if [[ -n "${EXPECTED_FILES}" ]]; then
  ACTUAL_FILES=$(find "${EXTRACTED_STORAGE}" -type f | wc -l | tr -d ' ')
  if [[ "${ACTUAL_FILES}" != "${EXPECTED_FILES}" ]]; then
    echo "[ERROR] Archivos de storage: backup=${EXPECTED_FILES}, restore=${ACTUAL_FILES}" >&2
    exit 1
  fi
  echo "  storage files: ${ACTUAL_FILES}"
fi

for dir in ${RESTORE_REQUIRED_DIRS}; do
  # Only the directories the source has: what matters is that nothing the
  # application wrote was left out of the archive.
  if [[ ! -d "${PRIVATE_STORAGE}/${dir}" ]]; then
    continue
  fi

  if [[ ! -d "${EXTRACTED_STORAGE}/${dir}" ]]; then
    echo "[ERROR] Directorio obligatorio no encontrado en el restore: ${dir}" >&2
    exit 1
  fi

  echo "  directorio: ${dir}"
done

echo "[${TIMESTAMP}] RESTORE PROOF EXITOSO"
echo "  Backup verificado: ${BACKUP_PATH}"
echo "  BD disposable eliminada: ${DISPOSABLE_DB}"
echo "  Directorio disposable eliminado: ${DISPOSABLE_DIR}"
exit 0
