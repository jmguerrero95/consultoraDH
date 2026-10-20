#!/usr/bin/env bash
# Healthy means "a backup completed recently", not "the process is running".
set -Eeuo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_MAX_AGE_HOURS="${BACKUP_MAX_AGE_HOURS:-36}"
MARKER="${BACKUP_DIR}/.last-success"

if [[ ! -f "${MARKER}" ]]; then
  echo "no successful backup marker in ${BACKUP_DIR}"
  exit 1
fi

last=$(cat "${MARKER}" 2>/dev/null || echo 0)
if ! [[ "${last}" =~ ^[0-9]+$ ]]; then
  echo "invalid backup marker"
  exit 1
fi

now=$(date -u +%s)
age=$(( now - last ))
max_age=$(( BACKUP_MAX_AGE_HOURS * 3600 ))

if (( age > max_age )); then
  echo "last successful backup is ${age}s old (max ${max_age}s)"
  exit 1
fi

echo "last successful backup ${age}s ago"
