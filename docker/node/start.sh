#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - front-end development container
#
# Installs Node dependencies when they are missing **or incomplete** and then
# starts the Vite development server.
# ---------------------------------------------------------------------------
set -euo pipefail

cd /var/www/html

# 1. Node dependencies -------------------------------------------------------

# FIX-EXPORT-IMPORT-04. The old check was `[ ! -d node_modules ]`, which answers
# "is this directory there" and not "is this installation usable". A partial
# install — one that was interrupted, or one made from a `package.json` that has
# since gained a dependency — passes that check, and then Vite serves a page
# carrying an unresolved-import error while looking perfectly healthy: the
# health check only asks whether `/@vite/client` answers, and it does.
#
# So the dependencies are verified against `package.json` itself. Every name in
# `dependencies` has to be present **with a readable manifest**.
#
# The manifest is checked on disk rather than through `require.resolve()` because
# a package whose `exports` map does not publish `./package.json` would fail the
# resolve while being perfectly installed — and a check that cries wolf gets
# switched off, which is the same silence this is here to remove.
declared_dependencies() {
    # `console.log` is load-bearing: `node -e "<expression>"` evaluates and
    # discards, so without it this function prints nothing, the loop below never
    # runs, and the check silently passes forever — the very failure it exists
    # to catch, reproduced inside the check.
    node -e "console.log(Object.keys(require('/var/www/html/package.json').dependencies || {}).join('\\n'))"
}

missing_dependencies() {
    local package
    local missing=""

    while read -r package; do
        [ -z "$package" ] && continue

        if [ ! -f "node_modules/${package}/package.json" ]; then
            missing="${missing} ${package}"
        fi
    done <<< "$(declared_dependencies)"

    printf '%s' "$missing"
}

install_dependencies() {
    if [ -f package-lock.json ]; then
        npm ci --no-audit --no-fund
    else
        npm install --no-audit --no-fund
    fi
}

missing="$(missing_dependencies)"

if [ -n "$missing" ]; then
    echo "[consultora-dh] node_modules incomplete - installing:${missing}"
    install_dependencies
fi

if [ ! -d node_modules/.bin ]; then
    echo "[consultora-dh] node_modules/.bin not found - installing Node dependencies..."
    install_dependencies
fi

# Still missing after an install means the install itself cannot satisfy
# package.json. Saying so here, at startup, is the entire point: otherwise the
# first sign of it is a blank page that somebody debugs from the wrong end.
missing="$(missing_dependencies)"

if [ -n "$missing" ]; then
    echo "[consultora-dh] ERROR: still unresolved after install:${missing}" >&2
    echo "[consultora-dh] the front-end cannot build. Fix node_modules or the lock file." >&2
    exit 1
fi

# 2. Watching ----------------------------------------------------------------

# FIX-EXPORT-IMPORT-04, second half. This container bind-mounts the project
# from a Windows directory through WSL, and that path does not deliver inotify
# events. Vite keeps its transformed modules in an in-memory graph that only the
# watcher invalidates, so with polling off it goes on serving the transformation
# from the last time it was started — while the file on disk, the tests and the
# committed source all say something else.
#
# The failure has no symptom: the page loads, the health check is green, the
# tests pass, and only the browser is behind. Polling is what makes an edit
# reach the browser, so it is required here rather than optional.
if [ "${VITE_USE_POLLING:-0}" = "1" ] || [ "${VITE_USE_POLLING:-0}" = "true" ]; then
    echo "[consultora-dh] Vite watch: polling (bind mount without inotify)"
fi

# 3. Development server ------------------------------------------------------
# The server binds to 0.0.0.0 so that it is reachable both from the Windows
# browser (published port) and from the application container.
echo "[consultora-dh] starting Vite development server..."
exec npm run dev -- --host 0.0.0.0