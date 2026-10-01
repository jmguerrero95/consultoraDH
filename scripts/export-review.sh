#!/usr/bin/env bash
#
# Builds the archive that a reviewer receives.
#
# The archive is produced from a Git commit with `git archive`, never from the
# working directory. That distinction is the whole point of this script: the
# working tree contains things that must never leave the machine, such as
# storage/logs/laravel.log, which holds development reset URLs, session data
# and diagnostic output, or the .env file with real credentials. `git archive`
# can only see what was committed, and `.gitignore` decides what that is.
#
# The alternatives are all worse:
#   * zipping the project directory ships .env, logs, vendor, node_modules and
#     the built frontend;
#   * copying the tree after deleting those by hand depends on remembering to
#     delete the right ones, and the next person repeats it wrongly;
#   * hand-built ZIPs accumulate, and it becomes unclear which archive matches
#     which commit.
#
# Requirements, enforced rather than assumed:
#   * at least one commit must exist;
#   * the working tree must be clean, so the archive matches what was reviewed;
#   * HEAD is the only thing exported.
#
# Usage:
#   scripts/export-review.sh                 # HEAD
#   scripts/export-review.sh --verify-only   # inspect an existing archive
#
set -euo pipefail

readonly SCRIPT_NAME="${0##*/}"
readonly PREFIX="consultora-dh-review"

# Paths that must never appear in the archive.
#
# The guarantee comes from HOW the archive is built, not from this list: `git
# archive` exports the tracked files of a commit, so anything .gitignore rejects
# (.env, vendor, node_modules, public/build, logs, runtime state) cannot be in
# there even if it sits in the working directory.
#
# NOTE: `git archive` has no `--exclude` flag. Filtering is done by the
# `export-ignore` attribute in .gitattributes, which .gitignore already makes
# unnecessary for ignored paths, so this list is not used to exclude anything.
# It is the contract the verifier below enforces, so the archive is checked
# rather than assumed.
#
# Deliberately NOT matched, because they are meant to be in the archive:
#   .env.example            the documented contract, every secret left empty
#   storage/*/.gitignore    placeholders that keep the directories in Git
readonly FORBIDDEN_PATTERNS=(
    # A real environment file, and any variant of it except the documented
    # template. `.env.example` is the only one that belongs in the archive, and
    # it is a template with every secret left empty.
    '(^|/)\.env$'
    '(^|/)\.env\.(local|production|backing|staging|testing)$'
    # Repository internals.
    '(^|/)\.git/'
    '^consultora-dh/\.git$'
    # Runtime state: only the data files, never the .gitignore placeholders.
    '^consultora-dh/storage/logs/.+\.log$'
    '^consultora-dh/storage/framework/(cache|sessions|views|testing)/.+\.(php|json|log|txt|data)$'
    '^consultora-dh/storage/app/(private|public)/[^.]'
    # Dependencies and generated output.
    '(^|/)vendor/'
    '(^|/)node_modules/'
    '(^|/)public/hot$'
    '(^|/)public/build/'
    # Database dumps.
    '\.(sql|dump|sqlite)$'
    # Test artefacts.
    '(^|/)playwright-report/'
    '(^|/)test-results/'
    '(^|/)blob-report/'
)

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
info()  { printf '  %s\n' "$*"; }

# Must run from inside the repository, whatever the caller's directory was.
cd "$(git rev-parse --show-toplevel 2>/dev/null)" || {
    red "This script must run inside a Git repository."
    exit 1
}

readonly REPO_ROOT="$(pwd)"
readonly SHORT_COMMIT="$(git rev-parse --short=12 HEAD 2>/dev/null || true)"

verify_archive() {
    local archive="${1:-}"

    if [[ -z "$archive" ]]; then
        red "Nothing to verify: no archive given."
        exit 1
    fi

    if [[ ! -f "$archive" ]]; then
        red "The archive does not exist: $archive"
        exit 1
    fi

    green "Contents of $archive"
    unzip -Z1 "$archive" | sed 's/^/    /'

    # Anything on this list inside the archive is a failure, not a warning.
    local pattern
    local forbidden=''

    # One combined expression, so a single pass is enough.
    local expression=''
    for pattern in "${FORBIDDEN_PATTERNS[@]}"; do
        expression+="${expression:+|}${pattern}"
    done

    # Allowed paths are subtracted afterwards, which keeps every pattern a plain
    # POSIX extended expression: grep -E has no lookahead, and silently
    # relying on one would make the check weaker than it looks.
    local allowed
    allowed="$(printf '%s\n' \
        '/\.env\.example$' \
        '/\.gitignore$' \
        | sed 's|^|consultora-dh/|; s|/$||')"

    forbidden="$(unzip -Z1 "$archive" | grep -E "$expression" | grep -Ev "$allowed" || true)"

    if [[ -n "$forbidden" ]]; then
        red
        red "The archive contains files it must not contain:"
        printf '%s\n' "$forbidden" | sed 's/^/    /'
        red
        red "Do not share this archive."
        exit 1
    fi

    green
    green "Verified: no credentials, logs, runtime data, dependencies or build output."
}

if [[ "${1:-}" == "--verify-only" ]]; then
    verify_archive "${2:-}"
    exit 0
fi

if [[ $# -gt 0 ]]; then
    red "Unknown option: $1"
    printf 'Usage: %s [--verify-only <archive>]\n' "$SCRIPT_NAME"
    exit 1
fi

info "Repository: $REPO_ROOT"

# --- 1. There must be a commit to export -----------------------------------
if [[ -z "$SHORT_COMMIT" ]]; then
    red "There are no commits yet, so there is nothing to export."
    red "Commit the project first; this script never archives an uncommitted tree."
    exit 1
fi

info "HEAD: $SHORT_COMMIT"

# --- 2. The working tree must match that commit ----------------------------
# Without this check the archive could describe code that was never reviewed.
if [[ -n "$(git status --porcelain)" ]]; then
    red "The working tree has uncommitted changes:"
    git status --porcelain | head -20 | sed 's/^/    /'

    if [[ "$(git status --porcelain | wc -l)" -gt 20 ]]; then
        red "    … and more"
    fi

    red
    red "Commit or stash them first. The archive must match a reviewed commit."
    exit 1
fi

info "Working tree is clean."

# --- 3. Build the archive from the commit ----------------------------------
readonly OUTPUT_DIR="${REVIEW_OUTPUT_DIR:-$REPO_ROOT}"
readonly ARCHIVE="$OUTPUT_DIR/${PREFIX}-${SHORT_COMMIT}.zip"

# `git archive` on HEAD: the tracked files of that commit. No .git directory, no
# ignored files, no working directory state, no untracked file. That is the
# mechanism the exclusion guarantee rests on.
git archive \
    --format=zip \
    --prefix="consultora-dh/" \
    --output="$ARCHIVE" \
    HEAD

if [[ ! -f "$ARCHIVE" ]]; then
    red "The archive was not created: $ARCHIVE"
    exit 1
fi

green
green "Review archive created"
info "$ARCHIVE"
info "$(du -h "$ARCHIVE" | cut -f1)"
info "commit $SHORT_COMMIT"

verify_archive "$ARCHIVE"

green
echo
echo "  Share that file. Do not zip the project directory instead."