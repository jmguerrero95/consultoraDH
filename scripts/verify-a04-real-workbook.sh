#!/usr/bin/env bash
#
# §19: check the real workbook against the known fingerprint.
#
# Refuses to run if the file is absent, and never writes anything: the script parses and
# counts. See the PHP file beside this one for why only aggregates are printed.
#
# The workbook lives in `.local-fixtures/`, which is git-ignored. It is the raw evidence for
# the import and is never committed, archived or copied into a test.

set -euo pipefail

cd "$(dirname "$0")/.."

WORKBOOK="${1:-.local-fixtures/EMPRESA BLINDEN AÑO 2026.xlsx}"

if [ ! -f "$WORKBOOK" ]; then
    echo "The workbook is not at \"$WORKBOOK\"." >&2
    echo "§19 requires a local ignored copy; it is never versioned." >&2
    echo "Copy it there and run this again." >&2
    exit 1
fi

# A committed workbook would be a PII leak, so this refuses rather than warns.
if git check-ignore -q "$WORKBOOK" 2>/dev/null; then
    :
else
    echo "WARNING: \"$WORKBOOK\" is not git-ignored. Add it to .gitignore before committing." >&2
fi

exec php scripts/verify-a04-real-workbook.php "$WORKBOOK"
