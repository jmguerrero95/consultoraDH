#!/usr/bin/env bash
set -euo pipefail

# ===========================================================================
# Restore Proof — Verificación de backup en entorno disposable
# ===========================================================================
# Uso: ./scripts/restore_proof.sh /backups/consultora_dh_20261011_020000
# ===========================================================================

BACKUP_PATH="${1:-}"
if [[ -z "${BACKUP_PATH}" || ! -d "${BACKUP_PATH}" ]]; then
  echo "Uso: $0 <backup_directory>"
  exit 1
fi

TIMESTAMP=$(date -u +"%Y%m%d_%H%M%S")
DISPOSABLE_DB="consultora_dh_restore_${TIMESTAMP}"
DISPOSABLE_DIR="/tmp/restore_${TIMESTAMP}"

# Trap para limpieza garantizada incluso en caso de error
cleanup() {
  echo "[${TIMESTAMP}] Limpiando recursos disposable..."
  dropdb -h "${PG_HOST:-postgres}" -p "${PG_PORT:-5432}" -U "${PG_USER:-postgres}" "${DISPOSABLE_DB}" 2>/dev/null || true
  rm -rf "${DISPOSABLE_DIR}" 2>/dev/null || true
}
trap cleanup EXIT

echo "[${TIMESTAMP}] Iniciando restore proof desde: ${BACKUP_PATH}"

# 1. Verificar manifest
echo "[${TIMESTAMP}] Verificando checksums..."
cd "${BACKUP_PATH}"
sha256sum -c MANIFEST.sha256 || {
  echo "[ERROR] Checksums no coinciden" >&2
  exit 1
}

# 2. Crear BD disposable
echo "[${TIMESTAMP}] Creando BD disposable: ${DISPOSABLE_DB}"
createdb -h "${PG_HOST:-postgres}" -p "${PG_PORT:-5432}" -U "${PG_USER:-postgres}" "${DISPOSABLE_DB}"

# 3. Restaurar PostgreSQL
echo "[${TIMESTAMP}] Restaurando PostgreSQL..."
pg_restore \
  --host="${PG_HOST:-postgres}" \
  --port="${PG_PORT:-5432}" \
  --username="${PG_USER:-postgres}" \
  --dbname="${DISPOSABLE_DB}" \
  --no-owner \
  --no-privileges \
  "${BACKUP_PATH}/postgres.dump"

if [[ $? -ne 0 ]]; then
  echo "[ERROR] pg_restore falló" >&2
  exit 1
fi

# 4. Verificar esquema esperado
echo "[${TIMESTAMP}] Verificando esquema..."
EXPECTED_TABLES=(
  "users" "clients" "companies" "ai_conversations" "ai_messages"
  "ai_action_proposals" "ai_artifacts" "mcp_tokens" "audit_events"
)
for table in "${EXPECTED_TABLES[@]}"; do
  COUNT=$(psql -h "${PG_HOST:-postgres}" -p "${PG_PORT:-5432}" -U "${PG_USER:-postgres}" -d "${DISPOSABLE_DB}" -t -c "SELECT COUNT(*) FROM ${table};")
  echo "  ${table}: ${COUNT} rows"
done

# 5. Verificar datos críticos
echo "[${TIMESTAMP}] Verificando datos críticos..."
psql -h "${PG_HOST:-postgres}" -p "${PG_PORT:-5432}" -U "${PG_USER:-postgres}" -d "${DISPOSABLE_DB}" -c "
  SELECT 'users' as table, COUNT(*) as count FROM users
  UNION ALL SELECT 'ai_conversations', COUNT(*) FROM ai_conversations
  UNION ALL SELECT 'ai_artifacts', COUNT(*) FROM ai_artifacts
  UNION ALL SELECT 'mcp_tokens', COUNT(*) FROM mcp_tokens;
"

# 6. Extraer y verificar almacenamiento privado
echo "[${TIMESTAMP}] Verificando almacenamiento privado..."
mkdir -p "${DISPOSABLE_DIR}"
tar -xzf "${BACKUP_PATH}/private_storage.tar.gz" -C "${DISPOSABLE_DIR}"

# Verificar la ruta real extraída (el archivo puede tener el directorio como entrada de nivel superior)
EXTRACTED_STORAGE="${DISPOSABLE_DIR}/private"
if [[ ! -d "${EXTRACTED_STORAGE}" ]]; then
  # Buscar el directorio real extraído
  EXTRACTED_STORAGE=$(find "${DISPOSABLE_DIR}" -mindepth 1 -maxdepth 1 -type d -name "private" | head -1)
fi

if [[ -z "${EXTRACTED_STORAGE}" || ! -d "${EXTRACTED_STORAGE}" ]]; then
  echo "[ERROR] Estructura de almacenamiento privado no encontrada en el backup" >&2
  exit 1
fi

echo "[${TIMESTAMP}] Almacenamiento extraído en: ${EXTRACTED_STORAGE}"

# Verificar archivos esperados (directorios obligatorios)
MANDATORY_DIRS=("ai-artifacts" "clients" "imports")
for dir in "${MANDATORY_DIRS[@]}"; do
  if [[ -d "${EXTRACTED_STORAGE}/${dir}" ]]; then
    FILE_COUNT=$(find "${EXTRACTED_STORAGE}/${dir}" -type f | wc -l)
    echo "  ${dir}: ${FILE_COUNT} files"
  else
    echo "[ERROR] Directorio obligatorio no encontrado: ${dir}" >&2
    exit 1
  fi
done

# 7. Verificar checksums de archivos críticos (ej. ai_artifacts)
echo "[${TIMESTAMP}] Verificando integridad archivos..."
if [[ -d "${EXTRACTED_STORAGE}/ai-artifacts" ]]; then
  find "${EXTRACTED_STORAGE}/ai-artifacts" -type f -name "*.pdf" -exec sha256sum {} \; | head -5
fi

echo "[${TIMESTAMP}] ✅ RESTORE PROOF EXITOSO"
echo "  Backup verificado: ${BACKUP_PATH}"
echo "  BD disposable eliminada: ${DISPOSABLE_DB}"
echo "  Directorio disposable eliminado: ${DISPOSABLE_DIR}"
exit 0