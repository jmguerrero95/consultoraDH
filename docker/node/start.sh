#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - front-end development container
#
# Installs Node dependencies on first boot (`node_modules` is a named volume,
# see compose.yaml) and then starts the Vite development server.
# ---------------------------------------------------------------------------
set -euo pipefail

cd /var/www/html

# 1. Node dependencies -------------------------------------------------------
if [ ! -d node_modules ] || [ ! -d node_modules/.bin ]; then
    echo "[consultora-dh] node_modules not found - installing Node dependencies..."
    if [ -f package-lock.json ]; then
        npm ci --no-audit --no-fund
    else
        npm install --no-audit --no-fund
    fi
fi

# 2. Development server ------------------------------------------------------
# The server binds to 0.0.0.0 so that it is reachable both from the Windows
# browser (published port) and from the application container.
echo "[consultora-dh] starting Vite development server..."
exec npm run dev -- --host 0.0.0.0
