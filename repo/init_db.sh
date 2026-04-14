#!/usr/bin/env bash
# init_db.sh
#
# Database initialization path for Research Services.
# Runs migrations and seeds reference data after the application is started.
#
# Prerequisites:
#   docker compose up --build must be run first (or pass --start to do it here).
#   docker compose up --build handles secret generation automatically via the
#   bootstrap service — no manual env-file setup or host-side secret tooling needed.
#
# Usage:
#   ./init_db.sh            — migrate + seed (assumes services already running)
#   ./init_db.sh --start    — also starts services first (docker compose up --build -d)
#   ./init_db.sh --reset    — destroy volumes, regenerate, re-migrate, re-seed
#
# This is for local development and initial deployment only.
# It is NOT the production secret-management path.

set -euo pipefail

START_SERVICES=false
RESET=false

for arg in "$@"; do
    case "$arg" in
        --start) START_SERVICES=true ;;
        --reset) RESET=true; START_SERVICES=true ;;
    esac
done

if $RESET; then
    echo "→ --reset: destroying volumes and regenerating"
    docker compose down -v --remove-orphans 2>/dev/null || true
fi

if $START_SERVICES; then
    echo "→ Starting services (docker compose up --build -d)"
    echo "  This will generate runtime secrets automatically if not already present."
    docker compose up --build -d
fi

# Wait for postgres to be healthy
echo "→ Waiting for database to be ready..."
RETRIES=30
until docker compose exec -T postgres pg_isready -U researchsvc -d researchsvc -q 2>/dev/null; do
    RETRIES=$((RETRIES - 1))
    if [ "$RETRIES" -le 0 ]; then
        echo "ERROR: database did not become ready in time."
        echo "       Ensure 'docker compose up --build -d' has been run."
        exit 1
    fi
    sleep 2
done

echo "→ Running database migrations"
docker compose exec -T app sh -c 'set -a; . /runtime/app.env; set +a; php artisan migrate --force'

echo "→ Seeding reference data and administrator account"
docker compose exec -T app sh -c 'set -a; . /runtime/app.env; set +a; php artisan db:seed --force'

HTTPS_PORT="${HTTPS_PORT:-8443}"
echo ""
echo "✓ Database initialized."
echo "  Application: https://localhost:${HTTPS_PORT}"
echo "  Default admin credentials printed above — change them immediately."
