#!/usr/bin/env bash
# ===========================================================================
# Live gate - are three independent providers really available?
# ===========================================================================
# Usage:
#   scripts/live_provider_gate.sh            # checks the environment
#   scripts/live_provider_gate.sh --print    # also prints the eligible providers
#
# ## Why this exists
#
# The LIVE job used to demand four credentials — Groq, Gemini, Cloudflare and
# OpenRouter — and failed when any of them was missing. That is stricter than
# the policy it is supposed to enforce: `docs/RELEASE_ACCEPTANCE_POLICY.md`
# asks for **three independent providers certified**, not for four particular
# ones.
#
# The consequence of the old rule was that a deployment with three perfectly
# good providers could not run the LIVE certification at all, because the
# fourth one was not configured. The gate was not measuring the thing the
# policy asks for.
#
# ## What this counts
#
# A provider counts when it has a credential **and** the extra field its
# endpoint needs — Cloudflare puts the account id in the URL, so a token alone
# is not a usable credential. Two models of the same provider are one provider:
# the count is of independent providers, not of models.
#
# The certification itself is not decided here. `scripts/live_certification.sh`
# makes the real requests and fails when fewer than three providers answer for
# real, which is a stricter question than this one.
# ===========================================================================
set -Eeuo pipefail

MIN_PROVIDERS="${MIN_PROVIDERS:-3}"

print=0
for argument in "$@"; do
  case "${argument}" in
    --print) print=1 ;;
    *) echo "Uso: $0 [--print]" >&2; exit 2 ;;
  esac
done

# A provider is eligible when it has a credential and whatever else its
# endpoint needs. The extra fields are checked with the same rule the
# certification uses to build the URL.
eligible() {
  local name="$1"

  case "${name}" in
    groq)
      [[ -n "${GROQ_API_KEY:-}" ]] && [[ -n "${GROQ_MODELS:-}" ]]
      ;;
    gemini)
      [[ -n "${GEMINI_API_KEY:-}" ]] && [[ -n "${GEMINI_MODELS:-}" ]]
      ;;
    cloudflare)
      # The token alone is not enough: the account id travels in the URL.
      [[ -n "${CLOUDFLARE_AI_API_TOKEN:-}" ]] \
        && [[ -n "${CLOUDFLARE_ACCOUNT_ID:-}" ]] \
        && [[ -n "${CLOUDFLARE_AI_MODELS:-}" ]]
      ;;
    openrouter)
      [[ -n "${OPENROUTER_API_KEY:-}" ]] && [[ -n "${OPENROUTER_MODELS:-}" ]]
      ;;
    *)
      return 1
      ;;
  esac
}

PROVIDERS=(groq gemini cloudflare openrouter)

eligible_list=()
missing_fields=()

for provider in "${PROVIDERS[@]}"; do
  if eligible "${provider}"; then
    eligible_list+=("${provider}")
  else
    missing_fields+=("${provider}")
  fi
done

count=${#eligible_list[@]}

if (( print == 1 )); then
  echo "Proveedores con credencial utilizable (${count}): ${eligible_list[*]:-ninguno}"
  if (( ${#missing_fields[@]} > 0 )); then
    echo "Sin credencial utilizable: ${missing_fields[*]}"
  fi
fi

if (( count < MIN_PROVIDERS )); then
  echo "[live] La política exige ${MIN_PROVIDERS} proveedores independientes con credencial utilizable; hay ${count}." >&2
  echo "[live] Configurados: ${eligible_list[*]:-ninguno}" >&2

  if (( ${#missing_fields[@]} > 0 )); then
    echo "[live] Sin credencial utilizable (o sin el campo extra que su endpoint necesita): ${missing_fields[*]}" >&2
  fi

  exit 1
fi

echo "[live] ${count} proveedores independientes con credencial utilizable (mínimo ${MIN_PROVIDERS})."
exit 0
