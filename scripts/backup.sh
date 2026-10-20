#!/usr/bin/env bash
# ===========================================================================
# Backup Consultora DH - PostgreSQL + private storage
# ===========================================================================
# Runs inside the dedicated `backup` sidecar (postgres:18.6 image, so the
# client tooling matches the server). It must not run in the PHP image: that
# image has pdo_pgsql but no pg_dump/psql/createdb.
#
# Environment (the compose service supplies all of these):
#   PGHOST, PGPORT, PGUSER, PGPASSWORD, PGDATABASE
#   BACKUP_DIR=/backups              persistent/external bind mount
#   PRIVATE_STORAGE=...              storage/app/private
#   RETENTION_DAYS=30
#   BACKUP_REQUIRE_ENCRYPTION=true   fail closed when no passphrase/key is set
#   BACKUP_ENCRYPTION_PASSPHRASE=... or BACKUP_ENCRYPTION_KEY_FILE=...
#   BACKUP_OFFSITE_DIR=...           optional second mount (off-host copy)
# ===========================================================================
set -Eeuo pipefail

umask 077

BACKUP_DIR="${BACKUP_DIR:-/backups}"
PG_HOST="${PGHOST:-${PG_HOST:-postgres}}"
PG_PORT="${PGPORT:-${PG_PORT:-5432}}"
PG_USER="${PGUSER:-${PG_USER:-consultora_dh_backup}}"
PG_DB="${PGDATABASE:-${PG_DB:-consultora_dh}}"
PRIVATE_STORAGE="${PRIVATE_STORAGE:-/var/www/html/storage/app/private}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
BACKUP_REQUIRE_ENCRYPTION="${BACKUP_REQUIRE_ENCRYPTION:-true}"
BACKUP_ENCRYPTION_PASSPHRASE="${BACKUP_ENCRYPTION_PASSPHRASE:-}"
BACKUP_ENCRYPTION_KEY_FILE="${BACKUP_ENCRYPTION_KEY_FILE:-}"
BACKUP_OFFSITE_DIR="${BACKUP_OFFSITE_DIR:-}"

TIMESTAMP=$(date -u +"%Y%m%d_%H%M%S")
BACKUP_NAME="consultora_dh_${TIMESTAMP}"
WORK_DIR="${BACKUP_DIR}/${BACKUP_NAME}"
COMPLETE=0

CRITICAL_TABLES=(
  users clients companies client_affiliations
  contribution_sheets generated_reports report_schedule_runs
  support_conversations support_messages automation_runs
  ai_conversations ai_messages ai_artifacts mcp_tokens audit_events
)

cleanup() {
  local status=$?
  if (( status != 0 && COMPLETE == 0 )); then
    echo "[ERROR] Backup incompleto; se elimina ${WORK_DIR}" >&2
    rm -rf "${WORK_DIR}"
  fi
}
trap cleanup EXIT

psql_run() {
  psql \
    --host="${PG_HOST}" \
    --port="${PG_PORT}" \
    --username="${PG_USER}" \
    --dbname="${PG_DB}" \
    --no-psqlrc \
    --set=ON_ERROR_STOP=1 \
    "$@"
}

encryption_configured() {
  [[ -n "${BACKUP_ENCRYPTION_PASSPHRASE}" || ( -n "${BACKUP_ENCRYPTION_KEY_FILE}" && -f "${BACKUP_ENCRYPTION_KEY_FILE}" ) ]]
}

encrypt_file() {
  local source="$1"
  local target="${source}.gpg"

  if [[ -n "${BACKUP_ENCRYPTION_PASSPHRASE}" ]]; then
    gpg --batch --yes --pinentry-mode loopback \
      --symmetric --cipher-algo AES256 \
      --passphrase-fd 3 \
      --output "${target}" \
      "${source}" 3<<<"${BACKUP_ENCRYPTION_PASSPHRASE}"
  else
    gpg --batch --yes --pinentry-mode loopback \
      --symmetric --cipher-algo AES256 \
      --passphrase-file "${BACKUP_ENCRYPTION_KEY_FILE}" \
      --output "${target}" \
      "${source}"
  fi

  rm -f "${source}"
}

echo "[${TIMESTAMP}] Iniciando backup: ${BACKUP_NAME}"

if [[ "${BACKUP_REQUIRE_ENCRYPTION}" == "true" ]] && ! encryption_configured; then
  echo "[ERROR] Backup cifrado obligatorio y no hay BACKUP_ENCRYPTION_PASSPHRASE ni BACKUP_ENCRYPTION_KEY_FILE." >&2
  exit 1
fi

if ! encryption_configured; then
  echo "[WARN] Backup sin cifrar: configure BACKUP_ENCRYPTION_PASSPHRASE o BACKUP_ENCRYPTION_KEY_FILE." >&2
fi

if [[ ! -d "${PRIVATE_STORAGE}" ]]; then
  echo "[ERROR] No existe PRIVATE_STORAGE: ${PRIVATE_STORAGE}" >&2
  exit 1
fi

mkdir -p "${WORK_DIR}"

# 1. PostgreSQL - custom format (compressed)
echo "[${TIMESTAMP}] Dumping PostgreSQL..."
pg_dump \
  --host="${PG_HOST}" \
  --port="${PG_PORT}" \
  --username="${PG_USER}" \
  --dbname="${PG_DB}" \
  --format=custom \
  --compress=9 \
  --no-owner \
  --no-privileges \
  --file="${WORK_DIR}/postgres.dump"

# 2. Counts at the exact moment of the dump. The restore proof compares these
#    against the restored database, which is stronger than "the table exists".
echo "[${TIMESTAMP}] Registrando conteos de tablas críticas..."
: > "${WORK_DIR}/COUNTS.tsv"
for table in "${CRITICAL_TABLES[@]}"; do
  exists=$(psql_run --tuples-only --no-align \
    --command="SELECT to_regclass('public.${table}') IS NOT NULL")

  if [[ "${exists}" == "t" ]]; then
    count=$(psql_run --tuples-only --no-align \
      --command="SELECT count(*) FROM public.${table}")
    printf '%s\t%s\n' "${table}" "${count}" >> "${WORK_DIR}/COUNTS.tsv"
  else
    printf '%s\tmissing\n' "${table}" >> "${WORK_DIR}/COUNTS.tsv"
  fi
done

# 3. Private storage - archive contents, not the parent directory name.
echo "[${TIMESTAMP}] Archiving private storage..."
STORAGE_FILES=$(find "${PRIVATE_STORAGE}" -type f | wc -l | tr -d ' ')
tar -czf "${WORK_DIR}/private_storage.tar.gz" -C "${PRIVATE_STORAGE}" .

# 4. Encrypt payload files before writing the manifest. The manifest then
#    authenticates the bytes that are actually stored on disk.
ENCRYPTED="no"
if encryption_configured; then
  echo "[${TIMESTAMP}] Cifrando backup (AES256)..."
  for file in postgres.dump private_storage.tar.gz COUNTS.tsv; do
    encrypt_file "${WORK_DIR}/${file}"
  done
  ENCRYPTED="yes"
fi

# 5. Manifest with checksums and enough metadata for the restore proof.
echo "[${TIMESTAMP}] Generando manifest..."
{
  echo "# Backup: ${BACKUP_NAME}"
  echo "# Timestamp: ${TIMESTAMP}"
  echo "# PostgreSQL: ${PG_DB}@${PG_HOST}:${PG_PORT}"
  echo "# StorageFiles: ${STORAGE_FILES}"
  echo "# Encrypted: ${ENCRYPTED}"
  echo ""
  (cd "${WORK_DIR}" && sha256sum postgres.dump* private_storage.tar.gz* COUNTS.tsv*)
} > "${WORK_DIR}/MANIFEST.sha256"

COMPLETE=1

# 6. Optional off-host copy. BACKUP_OFFSITE_DIR must be a different mount.
if [[ -n "${BACKUP_OFFSITE_DIR}" ]]; then
  echo "[${TIMESTAMP}] Copiando a almacenamiento externo: ${BACKUP_OFFSITE_DIR}"
  mkdir -p "${BACKUP_OFFSITE_DIR}"
  cp -a "${WORK_DIR}" "${BACKUP_OFFSITE_DIR}/"
fi

# 7. Retention, local and off-host.
echo "[${TIMESTAMP}] Limpiando backups antiguos (>${RETENTION_DAYS} días)..."
find "${BACKUP_DIR}" -maxdepth 1 -type d -name "consultora_dh_*" -mtime +"${RETENTION_DAYS}" -exec rm -rf {} +

if [[ -n "${BACKUP_OFFSITE_DIR}" ]]; then
  find "${BACKUP_OFFSITE_DIR}" -maxdepth 1 -type d -name "consultora_dh_*" -mtime +"${RETENTION_DAYS}" -exec rm -rf {} +
fi

# 8. Success marker for the container health check. Written last, only after
#    every previous step succeeded.
date -u +%s > "${BACKUP_DIR}/.last-success"

echo "[${TIMESTAMP}] Backup completado: ${WORK_DIR}"
echo "  - encrypted: ${ENCRYPTED}"
echo "  - postgres.dump: $(du -h "${WORK_DIR}"/postgres.dump* | cut -f1)"
echo "  - private_storage.tar.gz: $(du -h "${WORK_DIR}"/private_storage.tar.gz* | cut -f1)"
echo "  - storage files: ${STORAGE_FILES}"

exit 0
