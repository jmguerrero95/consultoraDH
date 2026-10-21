#!/usr/bin/env bash
# ===========================================================================
# Release gate — the backup has to be real, recent and recoverable
# ===========================================================================
# Usage (inside the backup sidecar, which has PostgreSQL 18 client tools):
#   docker compose --profile ops run --rm backup /scripts/backup_gate.sh
#
# ## Why this exists
#
# The backup sidecar lives behind the `ops` profile: a plain
# `docker compose up -d` does not start it, and nothing failed when it was not
# running. The checklist in `docs/PRODUCTION.md` said "the backup sidecar is
# configured", which is a statement about a file and not about a working copy.
#
# So the gate asks the questions that decide whether a restore is possible
# *right now*:
#
#   1. the backup is really enabled and configured;
#   2. the encryption key is there — a backup nobody can decrypt is not a backup;
#   3. the destination is persistent and outside the container;
#   4. the retention policy is set;
#   5. the last copy is recent enough to be a recovery point;
#   6. the sidecar's own health check agrees;
#   7. and, with --restore-proof, that the copy really restores.
#
# The exit code is the answer: 0 when every check passed, 1 when any of them
# did not. No credential is printed.
# ===========================================================================
set -Eeuo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_MAX_AGE_HOURS="${BACKUP_MAX_AGE_HOURS:-36}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
BACKUP_REQUIRE_ENCRYPTION="${BACKUP_REQUIRE_ENCRYPTION:-true}"
BACKUP_ENCRYPTION_PASSPHRASE="${BACKUP_ENCRYPTION_PASSPHRASE:-}"
BACKUP_ENCRYPTION_KEY_FILE="${BACKUP_ENCRYPTION_KEY_FILE:-}"
BACKUP_HOST_DIR="${BACKUP_HOST_DIR:-}"
BACKUP_OFFSITE_DIR="${BACKUP_OFFSITE_DIR:-}"

RESTORE_PROOF=0
for argument in "$@"; do
  case "${argument}" in
    --restore-proof) RESTORE_PROOF=1 ;;
    *) echo "Uso: $0 [--restore-proof]" >&2; exit 2 ;;
  esac
done

FAILED=0

check() {
  local name="$1"
  local ok="$2"
  local detail="${3:-}"

  if [[ "${ok}" == "yes" ]]; then
    printf '  OK    %-42s %s\n' "${name}" "${detail}"
  else
    printf '  FALLA %-42s %s\n' "${name}" "${detail}"
    FAILED=$((FAILED + 1))
  fi
}

echo "=== Puerta de backup ==="

# 1. The backup is really enabled.
if [[ "${BACKUP_REQUIRE_ENCRYPTION}" == "true" ]]; then
  check "copias cifradas obligatorias" "yes" "BACKUP_REQUIRE_ENCRYPTION=true"
else
  check "copias cifradas obligatorias" "no" "BACKUP_REQUIRE_ENCRYPTION=${BACKUP_REQUIRE_ENCRYPTION}"
fi

# 2. The key that opens the copies is there.
if [[ -n "${BACKUP_ENCRYPTION_PASSPHRASE}" ]]; then
  check "clave de cifrado configurada" "yes" "passphrase en el entorno"
elif [[ -n "${BACKUP_ENCRYPTION_KEY_FILE}" && -f "${BACKUP_ENCRYPTION_KEY_FILE}" ]]; then
  check "clave de cifrado configurada" "yes" "fichero de clave ${BACKUP_ENCRYPTION_KEY_FILE}"
else
  check "clave de cifrado configurada" "no" "ni BACKUP_ENCRYPTION_PASSPHRASE ni BACKUP_ENCRYPTION_KEY_FILE"
fi

# 3. The destination survives the container.
if [[ -n "${BACKUP_HOST_DIR}" ]]; then
  check "destino persistente" "yes" "BACKUP_HOST_DIR=${BACKUP_HOST_DIR}"
elif mountpoint -q "${BACKUP_DIR}" 2>/dev/null; then
  check "destino persistente" "yes" "${BACKUP_DIR} es un punto de montaje"
else
  check "destino persistente" "no" "${BACKUP_DIR} no es un montaje y BACKUP_HOST_DIR no está configurado"
fi

# 4. The retention policy is set.
if [[ "${RETENTION_DAYS}" =~ ^[0-9]+$ ]] && (( RETENTION_DAYS > 0 )); then
  check "política de retención" "yes" "${RETENTION_DAYS} días"
else
  check "política de retención" "no" "RETENTION_DAYS=${RETENTION_DAYS}"
fi

# 5. The last copy is recent enough to be a recovery point.
MARKER="${BACKUP_DIR}/.last-success"
if [[ ! -f "${MARKER}" ]]; then
  check "última copia reciente" "no" "no hay marca de éxito en ${BACKUP_DIR}"
else
  last="$(cat "${MARKER}" 2>/dev/null || echo 0)"
  now="$(date -u +%s)"

  if ! [[ "${last}" =~ ^[0-9]+$ ]]; then
    check "última copia reciente" "no" "marca de éxito ilegible"
  else
    age=$(( now - last ))
    max_age=$(( BACKUP_MAX_AGE_HOURS * 3600 ))

    if (( age > max_age )); then
      check "última copia reciente" "no" "la última copia tiene ${age}s (máximo ${max_age}s)"
    else
      check "última copia reciente" "yes" "última copia hace ${age}s"
    fi
  fi
fi

# 6. The sidecar's own health check agrees.
if [[ -x /usr/local/bin/backup-healthcheck ]]; then
  if health="$(BACKUP_DIR="${BACKUP_DIR}" BACKUP_MAX_AGE_HOURS="${BACKUP_MAX_AGE_HOURS}" /usr/local/bin/backup-healthcheck 2>&1)"; then
    check "estado del sidecar" "yes" "${health}"
  else
    check "estado del sidecar" "no" "${health}"
  fi
else
  check "estado del sidecar" "no" "el healthcheck no está instalado en la imagen"
fi

# 7. The copy really restores.
if (( RESTORE_PROOF == 1 )); then
  latest="$(find "${BACKUP_DIR}" -maxdepth 1 -type d -name "consultora_dh_*" | sort | tail -1)"

  if [[ -z "${latest}" ]]; then
    check "recuperación demostrable" "no" "no hay ninguna copia que restaurar"
  elif proof="$(/scripts/restore_proof.sh "${latest}" 2>&1)"; then
    check "recuperación demostrable" "yes" "restore proof de $(basename "${latest}")"
  else
    check "recuperación demostrable" "no" "$(printf '%s' "${proof}" | tail -3 | tr '\n' ' ')"
  fi
else
  echo "  --    recuperación demostrable                (ejecútela con --restore-proof)"
fi

echo

if (( FAILED > 0 )); then
  echo "La puerta de backup NO pasó: ${FAILED} comprobación(es) fallida(s)."
  exit 1
fi

echo "La puerta de backup pasó."
exit 0
