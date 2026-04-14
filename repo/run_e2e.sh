#!/usr/bin/env bash
# run_e2e.sh
#
# Playwright browser E2E test runner for Research Services.
#
# Spins up a dedicated, ephemeral Docker Compose environment (docker-compose.e2e.yml)
# with:
#   - PostgreSQL (tmpfs — destroyed on teardown)
#   - Redis
#   - PHP-FPM app  (SESSION_DRIVER=database, SESSION_SECURE_COOKIE=false)
#   - nginx (HTTP-only — no TLS, port 80)
#   - Playwright runner (mcr.microsoft.com/playwright:v1.49.0-jammy)
#
# Migrations + E2eSeeder run automatically before Playwright starts.
# Screenshots are captured for every test; artifacts land in e2e/test-results/.
#
# Requirements: Docker with Compose plugin only.
#
# Usage:
#   ./run_e2e.sh              — full E2E suite
#   ./run_e2e.sh --headed     — headed Chromium (requires DISPLAY or VNC)
#   ./run_e2e.sh --spec 01    — run only matching spec files (e.g. 01-auth)
#   ./run_e2e.sh --no-teardown — keep containers running for debugging

set -euo pipefail

COMPOSE_FILE="docker-compose.e2e.yml"
PROJECT="researchsvc-e2e"
DC="docker compose -f ${COMPOSE_FILE} -p ${PROJECT}"

PLAYWRIGHT_ARGS="--reporter=list"
NO_TEARDOWN=false
SPEC_FILTER=""

for arg in "$@"; do
    case "$arg" in
        --headed)      PLAYWRIGHT_ARGS="${PLAYWRIGHT_ARGS} --headed" ;;
        --no-teardown) NO_TEARDOWN=true ;;
        --spec)        shift; SPEC_FILTER="$1" ;;
        --spec=*)      SPEC_FILTER="${arg#--spec=}" ;;
    esac
done

# ── Cleanup trap ──────────────────────────────────────────────────────────────
cleanup() {
    local EXIT_CODE=$?
    echo ""
    if [ "$NO_TEARDOWN" = "true" ]; then
        echo "→ --no-teardown: containers left running for debugging"
        echo "  Run: docker compose -f ${COMPOSE_FILE} -p ${PROJECT} down -v --remove-orphans"
    else
        echo "→ Tearing down E2E environment"
        $DC down -v --remove-orphans 2>/dev/null || true
    fi
    exit $EXIT_CODE
}
trap cleanup EXIT

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo " Research Services — Playwright E2E Suite"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# ── Build ─────────────────────────────────────────────────────────────────────
echo "→ Building E2E app image"
$DC build --quiet app

# ── Start infrastructure ──────────────────────────────────────────────────────
echo "→ Starting E2E environment (bootstrap → postgres/redis → app → nginx)"
$DC up -d bootstrap postgres redis app nginx

# Wait for postgres
echo "→ Waiting for PostgreSQL"
RETRIES=60
until $DC exec -T postgres \
    pg_isready -U researchsvc_e2e -d researchsvc_e2e -q 2>/dev/null; do
    RETRIES=$((RETRIES - 1))
    if [ "$RETRIES" -le 0 ]; then
        echo "ERROR: PostgreSQL did not become ready in time"
        exit 1
    fi
    sleep 1
done

# Wait for app container to be healthy (php-fpm port 9000 must be listening)
echo "→ Waiting for app (php-fpm) to be ready"
RETRIES=60
until $DC ps app 2>/dev/null | grep -q "healthy"; do
    RETRIES=$((RETRIES - 1))
    if [ "$RETRIES" -le 0 ]; then
        echo "ERROR: app container (php-fpm) did not become healthy in time"
        $DC logs --tail=30 app 2>/dev/null || true
        exit 1
    fi
    sleep 2
done

# ── Migrations ────────────────────────────────────────────────────────────────
echo "→ Running migrations"
$DC exec -T app \
    sh -c 'set -a; . /runtime/app.env; set +a; php artisan migrate --force'

# ── Seed E2E fixtures ─────────────────────────────────────────────────────────
echo "→ Seeding E2E fixtures"
$DC exec -T app \
    sh -c 'set -a; . /runtime/app.env; set +a; php artisan db:seed --class=DataDictionarySeeder --force'
$DC exec -T app \
    sh -c 'set -a; . /runtime/app.env; set +a; php artisan db:seed --class=RolesPermissionsSeeder --force'
$DC exec -T app \
    sh -c 'set -a; . /runtime/app.env; set +a; php artisan db:seed --class=SystemConfigSeeder --force'
$DC exec -T app \
    sh -c 'set -a; . /runtime/app.env; set +a; php artisan db:seed --class=E2eSeeder --force'

# ── Wait for nginx to be healthy, then verify the full stack ─────────────────
# nginx healthcheck (port 80 /proc/net/tcp) runs automatically via Docker.
# Once healthy, verify the full stack from inside the app container:
#   app container → http://nginx/login → nginx proxy → php-fpm → Laravel
# The app container (PHP Alpine) has BusyBox wget; the nginx container may not.
echo "→ Waiting for nginx to be healthy"
RETRIES=30
until $DC ps nginx 2>/dev/null | grep -q "healthy"; do
    RETRIES=$((RETRIES - 1))
    if [ "$RETRIES" -le 0 ]; then
        echo "ERROR: nginx did not become healthy in time"
        $DC logs --tail=30 nginx 2>/dev/null || true
        exit 1
    fi
    sleep 2
done

echo "→ Verifying full stack (app → nginx → php-fpm)"
RETRIES=20
until $DC exec -T app \
    wget -qO- http://nginx/login 2>/dev/null | grep -q 'Research Services'; do
    RETRIES=$((RETRIES - 1))
    if [ "$RETRIES" -le 0 ]; then
        echo "ERROR: application did not serve the login page in time"
        $DC logs --tail=30 app 2>/dev/null || true
        exit 1
    fi
    sleep 2
done
echo "  Stack verified — login page is reachable"

# ── Run Playwright ────────────────────────────────────────────────────────────
echo ""
echo "→ Running Playwright tests"
echo "  Screenshots → e2e/test-results/"
echo ""

SPEC_ARG=""
if [ -n "$SPEC_FILTER" ]; then
    SPEC_ARG="tests/${SPEC_FILTER}"
fi

# Run Playwright inside the playwright container (same Docker network)
$DC run --rm \
    -e BASE_URL=http://nginx \
    -e CI=true \
    playwright \
    sh -c "npm ci --prefer-offline && npx playwright test ${PLAYWRIGHT_ARGS} ${SPEC_ARG}"

PLAYWRIGHT_EXIT=$?

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if [ "$PLAYWRIGHT_EXIT" -eq 0 ]; then
    echo " E2E suite PASSED"
else
    echo " E2E suite FAILED (exit code: ${PLAYWRIGHT_EXIT})"
    echo " Screenshots in: e2e/test-results/"
fi
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
exit $PLAYWRIGHT_EXIT
