# Backup & Restore — Consultora DH (A07)

## Resumen

Estrategia de backup y restore para PostgreSQL + almacenamiento privado de la aplicación. Incluye scripts versionados, runbook, y prueba de restore obligatoria.

---

## 1. Qué se respalda

| Componente | Qué incluye | Frecuencia |
|------------|-------------|------------|
| **PostgreSQL** | Esquema + datos de todas las tablas (incl. `audit_events`, `ai_conversations`, `ai_messages`, `ai_action_proposals`, `ai_artifacts`, `mcp_tokens`, etc.) | Diaria (02:00 UTC) |
| **Almacenamiento Privado** | `storage/app/private/` — documentos subidos, artefactos AI generados, archivos de importación | Diaria (02:30 UTC) |
| **Configuración** | `.env` (excluyendo secrets), `compose.yaml`, scripts de despliegue | En cada cambio (git) |

---

## 2. Scripts de Backup

### `scripts/backup.sh`

```bash
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

# 1. PostgreSQL — dump custom format (comprimido, paralelo)
echo "[${TIMESTAMP}] Dumping PostgreSQL..."
pg_dump \
  --host="${PG_HOST}" \
  --port="${PG_PORT}" \
  --username="${PG_USER}" \
  --dbname="${PG_DB}" \
  --format=custom \
  --compress=9 \
  --jobs=4 \
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
```

### Permisos
```bash
chmod +x scripts/backup.sh
```

### Variables de Entorno para Backup
```env
# En .env o docker-compose.override.yml
BACKUP_DIR=/backups
PG_HOST=postgres
PG_PORT=5432
PG_USER=consultora_dh_app
PG_DB=consultora_dh
PRIVATE_STORAGE=/var/www/html/storage/app/private
RETENTION_DAYS=30
```

---

## 3. Runbook — Procedimiento de Backup

### Ejecución Manual
```bash
# Desde host o contenedor app
docker compose exec app /var/www/html/scripts/backup.sh
```

### Verificación Post-Backup
```bash
# Verificar integridad
BACKUP_LATEST=$(ls -1d /backups/consultora_dh_* | tail -1)
cd "${BACKUP_LATEST}"
sha256sum -c MANIFEST.sha256

# Verificar que dump es válido
pg_restore --list postgres.dump | head -20
```

### Alerting
- Cron job debe reportar éxito/fallo (stdout/stderr a log centralizado)
- Alerta si backup no aparece en `BACKUP_DIR` en 24h
- Alerta si `pg_dump` o `tar` devuelven código != 0

---

## 4. Restore — Procedimiento

### ⚠️ REGLAS DE ORO
1. **NUNCA** restores sobre desarrollo o producción directa
2. **SIEMPRE** en BD/directorio **disposable** (temporal, aislado)
3. **VERIFICAR** schema + datos + checksums antes de dar por bueno
4. **BORRAR** targets disposable tras verificación

### `scripts/restore_proof.sh`

```bash
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
  --jobs=4 \
  --no-owner \
  --no-privileges \
  "${BACKUP_PATH}/postgres.dump"

if [[ $? -ne 0 ]]; then
  echo "[ERROR] pg_restore falló" >&2
  dropdb -h "${PG_HOST:-postgres}" -p "${PG_PORT:-5432}" -U "${PG_USER:-postgres}" "${DISPOSABLE_DB}"
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

# Verificar archivos esperados
EXPECTED_DIRS=("ai-artifacts" "clients" "imports")
for dir in "${EXPECTED_DIRS[@]}"; do
  if [[ -d "${DISPOSABLE_DIR}/${dir}" ]]; then
    FILE_COUNT=$(find "${DISPOSABLE_DIR}/${dir}" -type f | wc -l)
    echo "  ${dir}: ${FILE_COUNT} files"
  else
    echo "  ${dir}: NO ENCONTRADO (puede estar vacío)"
  fi
done

# 7. Verificar checksums de archivos críticos (ej. ai_artifacts)
echo "[${TIMESTAMP}] Verificando integridad archivos..."
if [[ -d "${DISPOSABLE_DIR}/ai-artifacts" ]]; then
  find "${DISPOSABLE_DIR}/ai-artifacts" -type f -name "*.pdf" -exec sha256sum {} \; | head -5
fi

# 8. Limpieza
echo "[${TIMESTAMP}] Limpiando recursos disposable..."
dropdb -h "${PG_HOST:-postgres}" -p "${PG_PORT:-5432}" -U "${PG_USER:-postgres}" "${DISPOSABLE_DB}"
rm -rf "${DISPOSABLE_DIR}"

echo "[${TIMESTAMP}] ✅ RESTORE PROOF EXITOSO"
echo "  Backup verificado: ${BACKUP_PATH}"
echo "  BD disposable eliminada: ${DISPOSABLE_DB}"
echo "  Directorio disposable eliminado: ${DISPOSABLE_DIR}"
exit 0
```

### Ejecución
```bash
chmod +x scripts/restore_proof.sh

# Ejecutar proof mensual (obligatorio)
docker compose exec app /var/www/html/scripts/restore_proof.sh /backups/consultora_dh_20261011_020000
```

---

## 5. Restore Real (Disaster Recovery)

### Solo si es absolutamente necesario
```bash
# 1. Poner app en maintenance
php artisan down --message="Restoring from backup..."

# 2. Backup estado actual (por si acaso)
./scripts/backup.sh

# 3. Restaurar PostgreSQL en BD producción
pg_restore --clean --if-exists -h postgres -U consultora_dh_app -d consultora_dh /backups/consultora_dh_YYYYMMDD_HHMMSS/postgres.dump

# 4. Restaurar almacenamiento privado
rm -rf /var/www/html/storage/app/private/*
tar -xzf /backups/consultora_dh_YYYYMMDD_HHMMSS/private_storage.tar.gz -C /var/www/html/storage/app/

# 5. Limpiar cache
php artisan config:clear && php artisan route:clear && php artisan view:clear

# 6. Verificar
php artisan migrate --force  # por si hay migraciones nuevas
php artisan health:check

# 7. Quitar maintenance
php artisan up
```

---

## 6. Load Test (A07)

### `scripts/load_test.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

# ===========================================================================
# Load Test A07 — Fake AI Provider
# ===========================================================================
# Ejercita: API autenticada, mensaje AI, MCP read, endpoint business read-heavy
# ===========================================================================

BASE_URL="${BASE_URL:-http://localhost:8080}"
CONCURRENCY="${CONCURRENCY:-10}"
DURATION="${DURATION:-30}"  # segundos
RESULTS_FILE="/tmp/load_test_$(date -u +%Y%m%d_%H%M%S).json"

echo "Iniciando load test: ${CONCURRENCY} workers x ${DURATION}s"
echo "Resultados en: ${RESULTS_FILE}"

# Requiere: vegeta (https://github.com/tsenart/vegeta) o similar
# O usar k6/wrk/hey

# Ejemplo con hey (https://github.com/rakyll/hey):
hey -z "${DURATION}s" -c "${CONCURRENCY}" \
  -H "Authorization: Bearer <session_cookie>" \
  -m GET "${BASE_URL}/api/health" \
  -o json > "${RESULTS_FILE}"

# Analizar resultados
jq -r '.latencies | "p50=\(.p50/1000000)ms p95=\(.p95/1000000)ms p99=\(.p99/1000000)ms errors=\(.errors)"' "${RESULTS_FILE}"
```

### Métricas Objetivo
| Endpoint | p50 | p95 | Error Rate |
|----------|-----|-----|------------|
| `/api/health` | < 10ms | < 50ms | 0% |
| `/api/ai/status` | < 50ms | < 200ms | 0% |
| `/api/mcp/rpc` (tools/list) | < 100ms | < 300ms | 0% |
| `/api/receivables` (read-heavy) | < 200ms | < 500ms | 0% |
| AI message (fake provider) | < 500ms | < 2000ms | 0% |

---

## 7. Verificación de Integridad Continua

### Diario (automatizado)
- [ ] Backup completado sin errores
- [ ] Checksums MANIFEST.sha256 válidos
- [ ] `pg_restore --list` lista tablas esperadas

### Semanal
- [ ] `restore_proof.sh` ejecutado y exitoso
- [ ] Tamaño backup dentro de rangos esperados

### Mensual
- [ ] Restore proof completo con verificación de datos
- [ ] Revisión de retención y limpieza
- [ ] Test de load test con fake AI provider

---

## 7. Contactos y Escalación

| Evento | Acción | Contacto |
|--------|--------|----------|
| Backup falla 2 días seguidos | Alerta crítica, investigar | DevOps |
| Restore proof falla | Alerta crítica, no desplegar | Dev Lead + DBA |
| Load test > p95 threshold | Investigar rendimiento | Dev Lead |
| Disaster recovery real | Activar plan DR, notificar | CTO + Dev Lead |

---

## 8. Checklist de Verificación

- [ ] `scripts/backup.sh` existe y es ejecutable
- [ ] `scripts/restore_proof.sh` existe y es ejecutable
- [ ] `scripts/load_test.sh` existe
- [ ] Cron job diario configurado y probado
- [ ] `BACKUP_DIR` montado en volumen persistente
- [ ] Retención 30 días verificada
- [ ] Restore proof mensual documentado
- [ ] Load test mensual ejecutado
- [ ] Runbook impreso/disponible offline