#!/usr/bin/env bash
# ===========================================================================
# The live gate's logic, proved with synthetic credentials
# ===========================================================================
# Usage:
#   scripts/test-live-provider-gate.sh
#
# ## What this is
#
# A check of the **decision**, not of the providers. No request leaves this
# machine: the gate is asked whether a set of credentials would let the LIVE
# certification start, and the answer has to be right for every combination
# that matters.
#
# It does not replace the certification. The certification makes real requests
# and fails when fewer than three providers answer for real; this proves the
# gate in front of it asks the question the policy asks.
# ===========================================================================
set -Eeuo pipefail

GATE="${GATE:-$(dirname "$0")/live_provider_gate.sh}"

PASSED=0
FAILED=0

# expect <description> <expected exit> <expected providers> -- <env assignments...>
expect() {
  local description="$1"
  local expected_status="$2"
  local expected_providers="$3"
  shift 3
  [[ "${1:-}" == "--" ]] && shift

  local output
  local status

  set +e
  output="$(env -u GROQ_API_KEY -u GROQ_MODELS -u GEMINI_API_KEY -u GEMINI_MODELS \
      -u CLOUDFLARE_AI_API_TOKEN -u CLOUDFLARE_ACCOUNT_ID -u CLOUDFLARE_AI_MODELS \
      -u OPENROUTER_API_KEY -u OPENROUTER_MODELS \
      "$@" bash "${GATE}" --print 2>&1)"
  status=$?
  set -e

  local ok=1

  if [[ "${status}" != "${expected_status}" ]]; then
    ok=0
    echo "  FALLA ${description}: exit ${status}, se esperaba ${expected_status}"
    echo "        salida: ${output}"
  fi

  if [[ -n "${expected_providers}" ]]; then
    if ! grep -q "${expected_providers}" <<< "${output}"; then
      ok=0
      echo "  FALLA ${description}: la salida no nombra '${expected_providers}'"
      echo "        salida: ${output}"
    fi
  fi

  if (( ok == 1 )); then
    echo "  OK    ${description}"
    PASSED=$((PASSED + 1))
  else
    FAILED=$((FAILED + 1))
  fi
}

echo "=== La puerta de proveedores LIVE ==="

# 1. Groq + Gemini + Cloudflare: the configuration this deployment has.
expect "Groq + Gemini + Cloudflare inician la certificación" 0 "groq gemini cloudflare" -- \
  GROQ_API_KEY=k GROQ_MODELS=m \
  GEMINI_API_KEY=k GEMINI_MODELS=m \
  CLOUDFLARE_AI_API_TOKEN=k CLOUDFLARE_ACCOUNT_ID=a CLOUDFLARE_AI_MODELS=m

# 2. Groq + Gemini + OpenRouter: OpenRouter replaces Cloudflare.
expect "Groq + Gemini + OpenRouter inician la certificación" 0 "groq gemini openrouter" -- \
  GROQ_API_KEY=k GROQ_MODELS=m \
  GEMINI_API_KEY=k GEMINI_MODELS=m \
  OPENROUTER_API_KEY=k OPENROUTER_MODELS=m

# 3. Only two providers: not enough.
expect "Sólo dos proveedores no pasan la puerta" 1 "" -- \
  GROQ_API_KEY=k GROQ_MODELS=m \
  GEMINI_API_KEY=k GEMINI_MODELS=m

# 4. Three names, but Cloudflare without its account id: Cloudflare does not
#    count, so only two remain and the gate refuses.
expect "Cloudflare sin account id no cuenta como tercer proveedor" 1 "" -- \
  GROQ_API_KEY=k GROQ_MODELS=m \
  GEMINI_API_KEY=k GEMINI_MODELS=m \
  CLOUDFLARE_AI_API_TOKEN=k CLOUDFLARE_AI_MODELS=m

# 5. Four configured, one without a credential: three remain and the gate
#    passes. This is the case the old rule refused.
expect "Cuatro nombrados con uno sin credencial siguen pasando" 0 "groq gemini cloudflare" -- \
  GROQ_API_KEY=k GROQ_MODELS=m \
  GEMINI_API_KEY=k GEMINI_MODELS=m \
  CLOUDFLARE_AI_API_TOKEN=k CLOUDFLARE_ACCOUNT_ID=a CLOUDFLARE_AI_MODELS=m \
  OPENROUTER_MODELS=m

# 6. Three configured, one of them without a credential: two remain, so the
#    gate refuses — and the certification would fail too.
expect "Tres nombrados con uno sin credencial no pasan" 1 "" -- \
  GROQ_API_KEY=k GROQ_MODELS=m \
  GEMINI_API_KEY=k GEMINI_MODELS=m \
  CLOUDFLARE_AI_MODELS=m \
  CLOUDFLARE_ACCOUNT_ID=a

# 7. An empty credential does not count.
expect "Una credencial vacía no cuenta" 1 "" -- \
  GROQ_API_KEY= GROQ_MODELS=m \
  GEMINI_API_KEY=k GEMINI_MODELS=m \
  CLOUDFLARE_AI_API_TOKEN=k CLOUDFLARE_ACCOUNT_ID=a CLOUDFLARE_AI_MODELS=m

# 8. A provider with a credential but no model does not count either: without a
#    model there is nothing to ask.
expect "Un proveedor sin modelo no cuenta" 1 "" -- \
  GROQ_API_KEY=k GROQ_MODELS= \
  GEMINI_API_KEY=k GEMINI_MODELS=m \
  CLOUDFLARE_AI_API_TOKEN=k CLOUDFLARE_ACCOUNT_ID=a CLOUDFLARE_AI_MODELS=m

# 9. Four real providers: all four count, and the gate still passes.
expect "Los cuatro proveedores cuentan como cuatro" 0 "groq gemini cloudflare openrouter" -- \
  GROQ_API_KEY=k GROQ_MODELS=m \
  GEMINI_API_KEY=k GEMINI_MODELS=m \
  CLOUDFLARE_AI_API_TOKEN=k CLOUDFLARE_ACCOUNT_ID=a CLOUDFLARE_AI_MODELS=m \
  OPENROUTER_API_KEY=k OPENROUTER_MODELS=m

echo
echo "Comprobaciones: ${PASSED} correctas, ${FAILED} fallidas."

if (( FAILED > 0 )); then
  exit 1
fi

exit 0
