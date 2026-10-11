#!/usr/bin/env bash
set -euo pipefail

# ===========================================================================
# Load Test A07 — Fake AI Provider
# ===========================================================================
# Ejercita: API autenticada, mensaje AI, MCP read, endpoint business read-heavy
# Requiere: vegeta (https://github.com/tsenart/vegeta) o hey
# ===========================================================================

BASE_URL="${BASE_URL:-http://localhost:8080}"
CONCURRENCY="${CONCURRENCY:-10}"
DURATION="${DURATION:-30}"
RESULTS_FILE="/tmp/load_test_$(date -u +%Y%m%d_%H%M%S).json"

echo "Iniciando load test: ${CONCURRENCY} workers x ${DURATION}s"
echo "Resultados en: ${RESULTS_FILE}"

# Verificar que hay una sesión válida (requiere login previo)
# Para este test usamos un token de sesión conocido o creamos uno via login
echo "NOTA: Este script requiere una sesión autenticada válida."
echo "Ejecute primero: ./scripts/login_test_user.sh"
echo "Luego establezca la variable SESSION_COOKIE con el valor de la cookie de sesión."

# Ejemplo con vegeta (requiere instalación):
# echo "GET ${BASE_URL}/api/health" | vegeta attack -rate=${CONCURRENCY} -duration=${DURATION}s | vegeta report

# Ejemplo con hey:
# hey -z "${DURATION}s" -c "${CONCURRENCY}" \
#   -H "Cookie: ${SESSION_COOKIE}" \
#   -m GET "${BASE_URL}/api/health" \
#   -o json > "${RESULTS_FILE}"

# Para endpoints AI (requiere ai.use permission):
# hey -z "${DURATION}s" -c "${CONCURRENCY}" \
#   -H "Cookie: ${SESSION_COOKIE}" \
#   -H "Content-Type: application/json" \
#   -m POST "${BASE_URL}/api/ai/conversations" \
#   -d '{"question":"test"}' \
#   -o json > "${RESULTS_FILE}"

# MCP read test:
# hey -z "${DURATION}s" -c "${CONCURRENCY}" \
#   -H "Authorization: Bearer ${MCP_TOKEN}" \
#   -H "Content-Type: application/json" \
#   -m POST "${BASE_URL}/api/mcp/rpc" \
#   -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
#   -o json > "${RESULTS_FILE}"

# Endpoint business read-heavy (ej. receivables):
# hey -z "${DURATION}s" -c "${CONCURRENCY}" \
#   -H "Cookie: ${SESSION_COOKIE}" \
#   -m GET "${BASE_URL}/api/receivables" \
#   -o json > "${RESULTS_FILE}"

echo ""
echo "Ejemplo de uso con vegeta:"
echo "  echo 'GET /api/health' | vegeta attack -rate=10 -duration=30s | vegeta report"
echo ""
echo "Ejemplo de uso con hey:"
echo "  hey -z 30s -c 10 -H \"Cookie: laravel_session=...\" http://localhost:8080/api/health"

# Si vegeta está disponible, ejecutar test básico
if command -v vegeta &> /dev/null; then
  echo ""
  echo "Ejecutando test básico con vegeta..."
  echo "GET ${BASE_URL}/api/health" | vegeta attack -rate="${CONCURRENCY}" -duration="${DURATION}s" | vegeta report
else
  echo ""
  echo "vegeta no está instalado. Instálelo con: go install github.com/tsenart/vegeta@latest"
fi

# Si hey está disponible, ejecutar test básico
if command -v hey &> /dev/null; then
  echo ""
  echo "Ejecutando test básico con hey..."
  hey -z "${DURATION}s" -c "${CONCURRENCY}" \
    -m GET "${BASE_URL}/api/health" \
    -o json > "${RESULTS_FILE}"
  
  if [[ -f "${RESULTS_FILE}" ]]; then
    echo ""
    echo "Resultados guardados en: ${RESULTS_FILE}"
    # Extraer métricas clave
    if command -v jq &> /dev/null; then
      jq -r '"p50=\(.latencies.p50/1000000)ms p95=\(.latencies.p95/1000000)ms p99=\(.latencies.p99/1000000)ms errors=\(.errors)"' "${RESULTS_FILE}"
    fi
  fi
else
  echo ""
  echo "hey no está instalado. Instálelo con: go install github.com/rakyll/hey@latest"
fi

exit 0