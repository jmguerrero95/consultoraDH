#!/usr/bin/env bash
set -euo pipefail

# ===========================================================================
# Backup Consultora DH — PostgreSQL + Private Storage
# ===========================================================================
# Ejecutar como cron diario: 0 2 * * * /var/www/html/scripts/backup.sh
# ===========================================================================

# Configuración (ajustar via env vars)
BACKUP_DIR="${BACKUP_DIR:-/backups}"
PG_HOST="${PG_HOST:-postgres}"
PG_PORT="${PG_PORT:-5432}"
PG_USER="${PG_USER:-consultora_dh_app}"
PG_DB="${PG_DB:-consultora_dh}"
PRIVATE_STORAGE="${PRIVATE_STORAGE:-/var/www/html/storage/app/private}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
TIMESTAMP=$(date -u +"%Y%m%d_%H%M%S")
BACKUP_NAME="consultora_dh_${TIMESTAMP}"

# Directorio de trabajo
WORK_DIR="${BACKUP_DIR}/${BACKUP_NAME}"
mkdir -p "${WORK_DIR}"

echo "[${TIMESTAMP}] Iniciando backup: ${BACKUP_NAME}"

# 1. PostgreSQL — dump custom format (comprimido)
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

if [[ $? -ne 0 ]]; then
  echo "[ERROR] pg_dump falló" >&2
  exit 1
fi

# 2. Almacenamiento privado — tar.gz
echo "[${TIMESTAMP}] Archiving private storage..."
tar -czf "${WORK_DIR}/private_storage.tar.gz" \
  -C "$(dirname "${PRIVATE_STORAGE}")" \
  "$(basename "${PRIVATE_STORAGE}")"

if [[ $? -ne 0 ]]; then
  echo "[ERROR] tar falló" >&2
  exit 1
fi

# 3. Manifest con checksums
echo "[${TIMESTAMP}] Generando manifest..."
{
  echo "# Backup: ${BACKUP_NAME}"
  echo "# Timestamp: ${TIMESTAMP}"
  echo "# PostgreSQL: ${PG_DB}@${PG_HOST}:${PG_PORT}"
  echo "# Storage: ${PRIVATE_STORAGE}"
  echo ""
  sha256sum "${WORK_DIR}/postgres.dump" "${WORK_DIR}/private_storage.tar.gz"
} > "${WORK_DIR}/MANIFEST.sha256"

# 4. Limpieza retención
echo "[${TIMESTAMP}] Limpiando backups antiguos (>${RETENTION_DAYS} días)..."
find "${BACKUP_DIR}" -maxdepth 1 -type d -name "consultora_dh_*" -mtime +"${RETENTION_DAYS}" -exec rm -rf {} \;

echo "[${TIMESTAMP}] Backup completado: ${WORK_DIR}"
echo "  - postgres.dump: $(du -h "${WORK_DIR}/postgres.dump" | cut -f1)"
echo "  - private_storage.tar.gz: $(du -h "${WORK_DIR}/private_storage.tar.gz" | cut -f1)"
echo "  - MANIFEST.sha256: $(cat "${WORK_DIR}/MANIFEST.sha256" | tail -2)"

exit 0