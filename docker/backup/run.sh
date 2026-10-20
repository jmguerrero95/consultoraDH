#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Daily backup loop for the backup sidecar.
#
# The first backup runs at container start (so a fresh deployment has an
# immediate recovery point), then every day at BACKUP_HOUR_UTC. A failed run
# is retried after BACKUP_RETRY_SECONDS instead of silently waiting 24 hours.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

BACKUP_HOUR_UTC="${BACKUP_HOUR_UTC:-2}"
BACKUP_RETRY_SECONDS="${BACKUP_RETRY_SECONDS:-300}"

trap 'echo "[backup] stopping"; exit 0' TERM INT

next_run_at() {
  local now target
  now=$(date -u +%s)
  target=$(date -u -d "today ${BACKUP_HOUR_UTC}:00:00" +%s)

  if (( target <= now )); then
    target=$(( target + 86400 ))
  fi

  echo "${target}"
}

run_backup() {
  echo "[backup] $(date -u +%Y-%m-%dT%H:%M:%SZ) starting"
  if /scripts/backup.sh; then
    echo "[backup] $(date -u +%Y-%m-%dT%H:%M:%SZ) succeeded"
    return 0
  fi

  echo "[backup] $(date -u +%Y-%m-%dT%H:%M:%SZ) FAILED" >&2
  return 1
}

run_backup || true
next_run=$(next_run_at)

while true; do
  now=$(date -u +%s)
  delay=$(( next_run - now ))

  if (( delay > 0 )); then
    sleep "${delay}" &
    wait $! || true
  fi

  if run_backup; then
    next_run=$(next_run_at)
  else
    next_run=$(( $(date -u +%s) + BACKUP_RETRY_SECONDS ))
  fi
done
