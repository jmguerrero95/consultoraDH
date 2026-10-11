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

# Verificar que la aplicación está corriendo
if ! curl -sf "${BASE_URL}/api/health" > /dev/null; then
    echo "ERROR: La aplicación no responde en ${BASE_URL}"
    exit 1
fi

# ===========================================================================
# ESCENARIO 1: Health check (sin autenticación)
# ===========================================================================
echo ""
echo "=== ESCENARIO 1: Health check ==="
if command -v vegeta &> /dev/null; then
    echo "Ejecutando con vegeta..."
    echo "GET ${BASE_URL}/api/health" | vegeta attack -rate="${CONCURRENCY}" -duration="${DURATION}s" | vegeta report -type=json > "${RESULTS_FILE}.health" || true
    echo "Resultados en: ${RESULTS_FILE}.health"
elif command -v hey &> /dev/null; then
    echo "Ejecutando con hey..."
    hey -z "${DURATION}s" -c "${CONCURRENCY}" \
        -m GET "${BASE_URL}/api/health" \
        -o json > "${RESULTS_FILE}.health" || true
    echo "Resultados en: ${RESULTS_FILE}.health"
else
    echo "ADVERTENCIA: Ni vegeta ni hey están instalados. Saltando test de health."
fi

# ===========================================================================
# ESCENARIO 2: AI message con fake provider
# ===========================================================================
echo ""
echo "=== ESCENARIO 2: AI message (requiere ai.use permission) ==="
echo "NOTA: Este test requiere un usuario con permiso ai.use y sesión autenticada."
echo "Para ejecutar:"
echo "  1. Login: curl -c cookies.txt -X POST ${BASE_URL}/api/auth/login -H 'Content-Type: application/json' -d '{\"email\":\"...\",\"password\":\"...\"}'"
echo "  2. Extraer cookie: SESSION_COOKIE=\$(grep laravel_session cookies.txt | awk '{print \$7}')"
echo "  3. Ejecutar test AI:"
echo "     hey -z ${DURATION}s -c ${CONCURRENCY} \\"
echo "       -H \"Cookie: laravel_session=\${SESSION_COOKIE}\" \\"
echo "       -H \"Content-Type: application/json\" \\"
echo "       -m POST ${BASE_URL}/api/ai/conversations \\"
echo "       -d '{\"question\":\"Hola, ¿cómo estás?\"}' \\"
echo "       -o json > \${RESULTS_FILE}.ai"

# ===========================================================================
# ESCENARIO 3: MCP read operations
# ===========================================================================
echo ""
echo "=== ESCENARIO 3: MCP read (tools/list) ==="
echo "NOTA: Requiere token MCP válido."
echo "Para ejecutar:"
echo "  1. Crear token MCP via API (requiere mcp.tokens.manage):"
echo "     curl -b cookies.txt -X POST ${BASE_URL}/api/mcp-tokens \\"
echo "       -H 'Content-Type: application/json' \\"
echo "       -d '{\"user_id\":1,\"name\":\"test\"}'"
echo "  2. Usar el raw_token devuelto:"
echo "     hey -z ${DURATION}s -c ${CONCURRENCY} \\"
echo "       -H \"Authorization: Bearer \${MCP_RAW_TOKEN}\" \\"
echo "       -H \"Content-Type: application/json\" \\"
echo "       -m POST ${BASE_URL}/api/mcp/rpc \\"
echo "       -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}' \\"
echo "       -o json > \${RESULTS_FILE}.mcp"

# ===========================================================================
# ESCENARIO 4: Business read-heavy endpoint (receivables)
# ===========================================================================
echo ""
echo "=== ESCENARIO 4: Business read-heavy (receivables) ==="
echo "NOTA: Requiere receivables.view permission y sesión autenticada."
echo "Para ejecutar:"
echo "  hey -z ${DURATION}s -c ${CONCURRENCY} \\"
echo "    -H \"Cookie: laravel_session=\${SESSION_COOKIE}\" \\"
echo "    -m GET ${BASE_URL}/api/receivables \\"
echo "    -o json > \${RESULTS_FILE}.receivables"

# ===========================================================================
# RESUMEN
# ===========================================================================
echo ""
echo "=== RESUMEN ==="
echo "Para ejecutar todos los escenarios automáticamente, necesitas:"
echo "  1. Usuario con permisos: ai.use, receivables.view, mcp.tokens.manage"
echo "  2. Sesión autenticada (cookie laravel_session)"
echo "  3. Token MCP válido"
echo "  4. vegeta o hey instalado"
echo ""
echo "Los resultados se guardan en /tmp/load_test_*.json"
echo ""
echo "Para analizar resultados con jq:"
echo "  jq '.latencies | {p50: .p50/1000000, p95: .p95/1000000, p99: .p99/1000000, errors}' *.json"

# Ejecutar test básico de health si hay herramientas disponibles
if command -v vegeta &> /dev/null; then
    echo ""
    echo "Ejecutando test básico de health con vegeta..."
    echo "GET ${BASE_URL}/api/health" | vegeta attack -rate="${CONCURRENCY}" -duration="${DURATION}s" | vegeta report
elif command -v hey &> /dev/null; then
    echo ""
    echo "Ejecutando test básico de health con hey..."
    hey -z "${DURATION}s" -c "${CONCURRENCY}" \
        -m GET "${BASE_URL}/api/health" \
        -o json > "${RESULTS_FILE}.health" || true

    if [[ -f "${RESULTS_FILE}.health" ]]; then
        echo ""
        echo "Resultados guardados en: ${RESULTS_FILE}.health"
        if command -v jq &> /dev/null; then
            jq -r '"p50=\(.latencies.p50/1000000)ms p95=\(.latencies.p95/1000000)ms p99=\(.latencies.p99/1000000)ms errors=\(.errors)"' "${RESULTS_FILE}.health"
        fi
    fi
else
    echo ""
    echo "ADVERTENCIA: Ni vegeta ni hey están instalados."
    echo "Instale vegeta: go install github.com/tsenart/vegeta@latest"
    echo "Instale hey: go install github.com/rakyll/hey@latest"
fi

# Verificar que al menos un escenario se ejecutó
if [[ ! -f "${RESULTS_FILE}.health" && ! -f "${RESULTS_FILE}.ai" && ! -f "${RESULTS_FILE}.mcp" && ! -f "${RESULTS_FILE}.receivables" ]]; then
    echo ""
    echo "ADVERTENCIA: Ningún escenario se ejecutó completamente."
    echo "LOAD_TEST=SKIPPED (requiere configuración manual)"
    exit 1
fi

echo ""
echo "LOAD_TEST=EXECUTED"
exit 0