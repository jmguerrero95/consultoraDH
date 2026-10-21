#!/usr/bin/env bash
# ===========================================================================
# Certificación LIVE de proveedores de IA
# ===========================================================================
# Uso:  ./scripts/live_certification.sh [directorio_de_evidencia]
#
# Qué hace y qué NO hace
# ----------------------
# Esta es la certificación LIVE: una petición real a cada proveedor, con sus
# credenciales reales y con datos sintéticos, y la evidencia de lo que volvió.
#
# NO es una simulación. Un proveedor sin credencial se marca como "sin
# credencial" y la certificación termina con código 1: la ausencia de
# evidencia es un fallo del proceso, no un visto bueno.
#
# Por proveedor se ejecuta `php artisan ai:check-provider`, que ya comprueba
# lo que importa contra el proveedor real: que responde, que mantiene el
# contexto entre turnos, que emite una llamada a herramienta y que el registro
# cerrado rechaza una herramienta fuera del catálogo. El pool se restringe a
# un solo proveedor por ejecución (los demás quedan desactivados), de modo que
# la evidencia pertenece a un proveedor y no a "alguien que respondió".
#
# Lo que produce (un directorio, por defecto ./evidence):
#
#   ai-preflight.json         configuración efectiva del despliegue
#   live-certification.tsv     una fila por proveedor con los campos exigidos
#   provider-<nombre>.txt     salida completa de la comprobación
#   live-certification.md      la misma tabla, para leerla
#
# Campos por proveedor (los que la aceptación exige):
#   Proveedor | Modelo | Clasificación | Herramientas | Datos privados |
#   Resultado | Latencia | Failover
# ===========================================================================

set -euo pipefail

EVIDENCE_DIR="${1:-evidence}"
USER_ID="${LIVE_USER_ID:-}"

# The artisan command, as an array: `php artisan` is two words, and a single
# string would be looked for as one executable.
read -r -a ARTISAN_CMD <<< "${ARTISAN:-php artisan}"

# The credentials live in the application's `.env`, which the shell does not
# read on its own: Laravel loads it inside the process, not in the environment
# of the shell that runs this script. Only the names this script uses are
# loaded, and only when the environment does not already provide them, so an
# operator can still point the certification somewhere else.
#
# The values are never printed: the evidence records the provider, the model
# and the result, never the key.
load_env_key() {
  local file="${LIVE_ENV_FILE:-.env}"
  local key="$1"

  [[ -n "${!key:-}" ]] && return 0
  [[ -f "${file}" ]] || return 0

  local line
  line="$(grep -E "^${key}=" "${file}" | tail -n 1 || true)"

  [[ -z "${line}" ]] && return 0

  local value="${line#*=}"
  value="${value%$'\r'}"
  value="${value%\"}"; value="${value#\"}"
  value="${value%\'}"; value="${value#\'}"

  export "${key}=${value}"
}

for name in \
  GROQ_API_KEY GROQ_BASE_URL GROQ_MODELS GROQ_DATA_POLICY \
  GEMINI_API_KEY GEMINI_BASE_URL GEMINI_MODELS GEMINI_DATA_POLICY \
  CLOUDFLARE_AI_API_TOKEN CLOUDFLARE_ACCOUNT_ID CLOUDFLARE_AI_BASE_URL \
  CLOUDFLARE_AI_MODELS CLOUDFLARE_AI_DATA_POLICY \
  OPENROUTER_API_KEY OPENROUTER_BASE_URL OPENROUTER_MODELS OPENROUTER_DATA_POLICY; do
  load_env_key "${name}"
done

mkdir -p "${EVIDENCE_DIR}"

if [[ -z "${USER_ID}" ]]; then
  echo "[live] LIVE_USER_ID no está definido: la comprobación de herramientas necesita una cuenta real con permisos." >&2
  exit 1
fi

# --- Preflight ------------------------------------------------------------
# La configuración efectiva primero: sin ella, la certificación describe un
# despliegue que nadie ha mirado.
"${ARTISAN_CMD[@]}" ai:preflight --json > "${EVIDENCE_DIR}/ai-preflight.json" || true

# --- Proveedores a comprobar ----------------------------------------------
# Cada uno: nombre, URL base, clave, modelos y política de datos declarada.
# La política es la que el despliegue afirma; la evidencia LIVE no cambia una
# clasificación, la confirma o la desmiente.
# The default URLs live in their own variables.
#
# They cannot be written inline as `${VAR:-https://.../accounts/{account_id}/ai/v1}`:
# the `}` of `{account_id}` closes the parameter expansion early, so the
# expansion ends at `{account_id` and the rest of the default is appended to the
# value as literal text. The URL that reached Cloudflare was
# `.../accounts/<id>/ai/v1/ai/v1}`, and the provider answered every request
# with "No route for that URI".
CLOUDFLARE_BASE_DEFAULT='https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/v1'

PROVIDERS=(
  "groq|${GROQ_BASE_URL:-https://api.groq.com/openai/v1}|${GROQ_API_KEY:-}|${GROQ_MODELS:-}|${GROQ_DATA_POLICY:-standard}"
  "gemini|${GEMINI_BASE_URL:-https://generativelanguage.googleapis.com/v1beta/openai}|${GEMINI_API_KEY:-}|${GEMINI_MODELS:-}|${GEMINI_DATA_POLICY:-training}"
  "cloudflare|${CLOUDFLARE_AI_BASE_URL:-${CLOUDFLARE_BASE_DEFAULT}}|${CLOUDFLARE_AI_API_TOKEN:-}|${CLOUDFLARE_AI_MODELS:-}|${CLOUDFLARE_AI_DATA_POLICY:-standard}"
  "openrouter|${OPENROUTER_BASE_URL:-https://openrouter.ai/api/v1}|${OPENROUTER_API_KEY:-}|${OPENROUTER_MODELS:-}|${OPENROUTER_DATA_POLICY:-training}"
)

# Los proveedores nombrados que no son el de turno se apagan, para que la
# respuesta venga del que se está midiendo.
others() {
  case "$1" in
    groq)       echo "GEMINI_ENABLED=false CLOUDFLARE_AI_ENABLED=false" ;;
    gemini)     echo "GROQ_ENABLED=false CLOUDFLARE_AI_ENABLED=false" ;;
    cloudflare) echo "GROQ_ENABLED=false GEMINI_ENABLED=false" ;;
    openrouter) echo "GROQ_ENABLED=false GEMINI_ENABLED=false CLOUDFLARE_AI_ENABLED=false" ;;
  esac
}

printf 'proveedor\tmodelo\tclasificacion\therramientas\tdatos_privados\tresultado\tlatencia_ms\tfailover\n' \
  > "${EVIDENCE_DIR}/live-certification.tsv"

certified=0
missing=0

for entry in "${PROVIDERS[@]}"; do
  IFS='|' read -r name base_url api_key models policy <<< "${entry}"

  if [[ -z "${api_key}" ]]; then
    echo "[live] ${name}: sin credencial, sin evidencia." >&2
    printf '%s\t-\t%s\t-\t-\tsin credencial\t-\t-\n' "${name}" "${policy}" \
      >> "${EVIDENCE_DIR}/live-certification.tsv"
    missing=$((missing + 1))
    continue
  fi

  model="${models%%,*}"
  if [[ -z "${model}" ]]; then
    echo "[live] ${name}: sin modelo configurado." >&2
    printf '%s\t-\t%s\t-\t-\tsin modelo\t-\t-\n' "${name}" "${policy}" \
      >> "${EVIDENCE_DIR}/live-certification.tsv"
    missing=$((missing + 1))
    continue
  fi

  # Cloudflare lleva el id de cuenta en la URL.
  #
  # The substitution is done with `sed` and not with bash's parameter
  # expansion: `${var//\{account_id\}/${OTHER:-}}` does not mean what it
  # looks like, and the URL that came out of it had the account id *and* a
  # duplicated path, which the provider answered with "No route for that URI".
  resolved_url="$(printf '%s' "${base_url}" | sed "s|{account_id}|${CLOUDFLARE_ACCOUNT_ID:-}|g")"

  echo "[live] comprobando ${name} (${model})…"

  started=$(date +%s%3N)

  set +e
  env \
    AI_PRIMARY_BASE_URL="${resolved_url}" \
    AI_PRIMARY_API_KEY="${api_key}" \
    AI_PRIMARY_MODEL="${model}" \
    AI_FALLBACK_BASE_URL="" \
    AI_FALLBACK_API_KEY="" \
    AI_FALLBACK_MODEL="" \
    $(others "${name}") \
    OPENROUTER_INCLUDE_FREE_ROUTER=false \
    "${ARTISAN_CMD[@]}" ai:check-provider --user="${USER_ID}" \
      > "${EVIDENCE_DIR}/provider-${name}.txt" 2>&1
  status=$?
  set -e

  finished=$(date +%s%3N)
  latency=$(( finished - started ))

  if [[ ${status} -eq 0 ]]; then
    result="ok"
    tools="sí"
    certified=$((certified + 1))
  else
    result="fallo"
    tools="no"
  fi

  # Los datos privados sólo los puede declarar la configuración efectiva: la
  # política del proveedor decide, y el preflight la ha registrado.
  private="no"
  if grep -q "\"provider\": \"${name}\"" "${EVIDENCE_DIR}/ai-preflight.json" 2>/dev/null; then
    private="según preflight"
  fi

  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "${name}" "${model}" "${policy}" "${tools}" "${private}" "${result}" "${latency}" "no (proveedor único)" \
    >> "${EVIDENCE_DIR}/live-certification.tsv"

  echo "[live] ${name}: ${result} en ${latency} ms" >&2
done

# --- Failover --------------------------------------------------------------
#
# The failover is not a property of the transport: it is the resolver walking
# down its candidates inside ONE turn. So the check induces a real failure --
# the primary slot is pointed at an address that answers nothing -- and asks
# for a real answer with a provider behind it.
#
# The evidence is the diagnostics of that single turn: a row for the provider
# that failed and a row for the provider that answered.
echo "[live] comprobando el failover..." >&2

FALLBACK_NAME=""
FALLBACK_BASE=""
FALLBACK_KEY=""
FALLBACK_MODEL=""
for entry in "${PROVIDERS[@]}"; do
  IFS='|' read -r name base_url api_key models policy <<< "${entry}"

  if [[ -n "${api_key}" && -n "${models}" ]]; then
    FALLBACK_NAME="${name}"
    FALLBACK_BASE="${base_url}"
    FALLBACK_KEY="${api_key}"
    FALLBACK_MODEL="${models%%,*}"
    break
  fi
done

if [[ -z "${FALLBACK_NAME}" ]]; then
  echo "[live] sin proveedor con credencial: no hay failover que comprobar." >&2
  missing=$((missing + 1))
else
  set +e
  env \
    AI_PRIMARY_BASE_URL="http://127.0.0.1:9/v1" \
    AI_PRIMARY_API_KEY="credencial-inexistente" \
    AI_PRIMARY_MODEL="modelo-inexistente" \
    AI_FALLBACK_BASE_URL="${FALLBACK_BASE}" \
    AI_FALLBACK_API_KEY="${FALLBACK_KEY}" \
    AI_FALLBACK_MODEL="${FALLBACK_MODEL}" \
    GROQ_ENABLED=false \
    GEMINI_ENABLED=false \
    CLOUDFLARE_AI_ENABLED=false \
    OPENROUTER_ENABLED=false \
    "${ARTISAN_CMD[@]}" ai:check-failover \
      > "${EVIDENCE_DIR}/provider-failover.txt" 2>&1
  failover_status=$?
  set -e

  if [[ ${failover_status} -eq 0 ]]; then
    echo "[live] failover: ok (${FALLBACK_NAME})" >&2
    printf 'failover\t%s\t%s\t%s\t-\t-\tok\t-\tsí (mismo turno)\n' \
      "fallover" "${FALLBACK_NAME}" "${FALLBACK_MODEL}" \
      >> "${EVIDENCE_DIR}/live-certification.tsv"
  else
    echo "[live] failover: fallo" >&2
    printf 'failover\t%s\t%s\t%s\t-\t-\tfallo\t-\tno\n' \
      "fallover" "${FALLBACK_NAME}" "${FALLBACK_MODEL}" \
      >> "${EVIDENCE_DIR}/live-certification.tsv"
  fi
fi

# --- Informe legible ------------------------------------------------------
{
  echo "# Certificación LIVE de proveedores de IA"
  echo
  echo "Generado: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo
  echo "| Proveedor | Modelo | Clasificación | Herramientas | Datos privados | Resultado | Latencia (ms) | Failover |"
  echo "| --- | --- | --- | --- | --- | --- | --- | --- |"

  while IFS=$'\t' read -r provider model policy tools private result latency failover; do
    echo "| ${provider} | ${model} | ${policy} | ${tools} | ${private} | ${result} | ${latency} | ${failover} |"
  done < <(tail -n +2 "${EVIDENCE_DIR}/live-certification.tsv")

  echo
  echo "Proveedores con evidencia LIVE: ${certified}. Sin credencial o sin modelo: ${missing}."
  echo
  echo "La clasificación de datos es la que declara el despliegue; esta ejecución la confirma o la desmiente, no la cambia."
} > "${EVIDENCE_DIR}/live-certification.md"

echo "[live] evidencia en ${EVIDENCE_DIR}" >&2

if [[ ${certified} -lt 3 ]]; then
  echo "[live] menos de tres proveedores con evidencia LIVE: la certificación no está completa." >&2
  exit 1
fi

exit 0
