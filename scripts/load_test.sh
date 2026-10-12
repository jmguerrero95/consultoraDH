#!/usr/bin/env bash
set -euo pipefail

# ===========================================================================
# Load Test A07 — executable
# ===========================================================================
#
# Runs the four scenarios this milestone added — health, an authenticated AI
# message, an authenticated MCP read, and a read-heavy business endpoint — and
# reports what actually happened.
#
# Design notes, because they decide what the numbers mean:
#
#   * Requests go through the real HTTP stack (routing, middleware group,
#     authorization, controller, database), so they describe this application.
#     They do not include a web server or the network in front of it.
#   * The AI provider is faked and local. A measurement that spends a token per
#     request would measure the provider's latency and its invoice.
#   * It runs against the isolated test database only. There is no path here by
#     which this script can reach development or production.
#   * A scenario counts as executed only when every request succeeded. A run in
#     which the assistant answers 403 twenty times is reported as a failure, not
#     as a fast load result.
#   * Nothing is printed except the measurements. The MCP token is generated,
#     used, and discarded in-process.
#
# Exit status:
#   0  every scenario ran and every request succeeded
#   1  a scenario failed, or the harness could not run
# ===========================================================================

cd "$(dirname "$0")/.."

CONTAINER="${LOAD_TEST_CONTAINER:-app}"

if ! docker compose ps --status running --services 2>/dev/null | grep -qx "${CONTAINER}"; then
    echo "LOAD_TEST_RESULT=NOT_EXECUTED"
    echo "reason: the '${CONTAINER}' service is not running."
    exit 1
fi

echo "=== A07 load test ==="
echo "Scenarios: health, ai_message, mcp_tools_list, business_read"
echo ""

# The suite refuses to run against the development database itself; this check
# exists so an unmigrated or misconfigured environment is reported as a reason
# rather than as a mysterious failure.
if ! docker compose exec -T "${CONTAINER}" php artisan migrate:status --database=testing >/dev/null 2>&1; then
    echo "LOAD_TEST_RESULT=NOT_EXECUTED"
    echo "reason: the isolated test database is not reachable."
    echo "        use scripts/reset-test-db.sh to rebuild it."
    exit 1
fi

OUTPUT="$(docker compose exec -T "${CONTAINER}" \
    vendor/bin/pest tests/Feature/A07/AiLoadTest.php 2>&1)" && STATUS=0 || STATUS=$?

echo "${OUTPUT}"
echo ""

# Pest writes its progress to stdout with escape sequences; the scenario lines are
# the plain lines it prints.
AI_REQ=$(printf '%s\n' "${OUTPUT}" | sed -n 's/^ai_message *req=\([0-9]*\).*/\1/p' | head -1)
AI_ERR=$(printf '%s\n' "${OUTPUT}" | sed -n 's/^ai_message *req=[0-9]* err=\([0-9]*\).*/\1/p' | head -1)
AI_P95=$(printf '%s\n' "${OUTPUT}" | sed -n 's/^ai_message .*p95=\([0-9.]*\)ms.*/\1/p' | head -1)

MCP_REQ=$(printf '%s\n' "${OUTPUT}" | sed -n 's/^mcp_tools_list *req=\([0-9]*\).*/\1/p' | head -1)
MCP_ERR=$(printf '%s\n' "${OUTPUT}" | sed -n 's/^mcp_tools_list *req=[0-9]* err=\([0-9]*\).*/\1/p' | head -1)
MCP_P95=$(printf '%s\n' "${OUTPUT}" | sed -n 's/^mcp_tools_list .*p95=\([0-9.]*\)ms.*/\1/p' | head -1)

BUS_REQ=$(printf '%s\n' "${OUTPUT}" | sed -n 's/^business_read *req=\([0-9]*\).*/\1/p' | head -1)
BUS_ERR=$(printf '%s\n' "${OUTPUT}" | sed -n 's/^business_read *req=[0-9]* err=\([0-9]*\).*/\1/p' | head -1)
BUS_P95=$(printf '%s\n' "${OUTPUT}" | sed -n 's/^business_read .*p95=\([0-9.]*\)ms.*/\1/p' | head -1)

HEALTH_REQ=$(printf '%s\n' "${OUTPUT}" | sed -n 's/^health *req=\([0-9]*\).*/\1/p' | head -1)
HEALTH_ERR=$(printf '%s\n' "${OUTPUT}" | sed -n 's/^health *req=[0-9]* err=\([0-9]*\).*/\1/p' | head -1)
HEALTH_P95=$(printf '%s\n' "${OUTPUT}" | sed -n 's/^health .*p95=\([0-9.]*\)ms.*/\1/p' | head -1)

TOTAL_ERRORS=$(( ${AI_ERR:-1} + ${MCP_ERR:-1} + ${BUS_ERR:-1} + ${HEALTH_ERR:-1} ))

echo "--- summary ---"
echo "health         requests=${HEALTH_REQ:-0} errors=${HEALTH_ERR:-?} p95=${HEALTH_P95:-?}ms"
echo "ai_message     requests=${AI_REQ:-0} errors=${AI_ERR:-?} p95=${AI_P95:-?}ms"
echo "mcp_tools_list requests=${MCP_REQ:-0} errors=${MCP_ERR:-?} p95=${MCP_P95:-?}ms"
echo "business_read  requests=${BUS_REQ:-0} errors=${BUS_ERR:-?} p95=${BUS_P95:-?}ms"

# A scenario that never reported a request count did not run.
for pair in "health:${HEALTH_REQ:-}" "ai_message:${AI_REQ:-}" \
            "mcp_tools_list:${MCP_REQ:-}" "business_read:${BUS_REQ:-}"; do
    name="${pair%%:*}"
    count="${pair#*:}"

    if [[ -z "${count}" || "${count}" -eq 0 ]]; then
        echo ""
        echo "LOAD_TEST_RESULT=NOT_EXECUTED"
        echo "reason: scenario '${name}' produced no measurements."
        exit 1
    fi
done

if [[ "${STATUS}" -ne 0 || "${TOTAL_ERRORS}" -ne 0 ]]; then
    echo ""
    echo "LOAD_TEST_RESULT=FAIL"
    echo "errors=${TOTAL_ERRORS} exit=${STATUS}"
    exit 1
fi

echo ""
echo "LOAD_TEST_RESULT=PASS"
echo "requests_total=$(( ${HEALTH_REQ:-0} + ${AI_REQ:-0} + ${MCP_REQ:-0} + ${BUS_REQ:-0} )) errors_total=${TOTAL_ERRORS}"
exit 0
