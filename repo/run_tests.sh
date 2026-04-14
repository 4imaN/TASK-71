#!/usr/bin/env bash
# run_tests.sh
#
# Broad test runner for Research Services.
# Runs the full test suite inside Docker.
#
# Requirements: Docker with Compose plugin only.
# No host PHP, no preconfigured database, no manual setup step.
# Uses the same bootstrap/value-generation model as the main runtime.
#
# Isolation: uses -p researchsvc-test so the test stack lives in its own Docker
# Compose project namespace, completely separate from the main runtime (which
# defaults to the directory name).  No orphan-container warnings, no volume
# collisions, no credential cross-contamination.
#
# Single-call startup: all services are started with ONE docker compose up call.
# This ensures the bootstrap service runs exactly once — generating a single
# consistent set of secrets that postgres, redis, and app all share.  Splitting
# startup across multiple 'up' calls causes docker compose to re-run bootstrap
# (REGENERATE=true) on the second call, producing a new DB_PASSWORD that no
# longer matches the password postgres was initialised with.
#
# Usage:
#   ./run_tests.sh                   — run all test suites
#   ./run_tests.sh --unit            — Unit suite only
#   ./run_tests.sh --feature         — Feature suite only
#   ./run_tests.sh --integration     — Integration suite only
#   ./run_tests.sh --coverage        — Add coverage report

set -euo pipefail

COMPOSE_FILE="docker-compose.test.yml"
# Dedicated project name — test containers/volumes stay in their own namespace.
PROJECT="researchsvc-test"
DC="docker compose -f ${COMPOSE_FILE} -p ${PROJECT}"
FILTER=""
COVERAGE=""

for arg in "$@"; do
    case "$arg" in
        --unit)        FILTER="--testsuite=Unit" ;;
        --feature)     FILTER="--testsuite=Feature" ;;
        --integration) FILTER="--testsuite=Integration" ;;
        --coverage)    COVERAGE="--coverage-text" ;;
    esac
done

cleanup() {
    echo ""
    echo "→ Tearing down test environment"
    $DC down -v --remove-orphans 2>/dev/null || true
}
trap cleanup EXIT

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo " Research Services — Test Suite"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

echo "→ Building test image"
$DC build --quiet app

# Start the entire test stack in one call so bootstrap runs exactly once.
# All four services (bootstrap → postgres, redis → app) share the same secret
# set written to the test_runtime_config volume.
echo "→ Starting test environment (bootstrap → postgres/redis → app)"
echo "  Secrets generated fresh on each run (REGENERATE=true)"
$DC up -d

# Wait for postgres to be healthy before running migrations.
echo "→ Waiting for postgres to be ready"
RETRIES=60
until $DC exec -T postgres \
    pg_isready -U researchsvc_test -d researchsvc_test -q 2>/dev/null; do
    RETRIES=$((RETRIES - 1))
    if [ "$RETRIES" -le 0 ]; then
        echo "ERROR: test database did not become ready in time"
        exit 1
    fi
    sleep 1
done

# Wait for the app container to be running (it starts after postgres/redis healthy).
echo "→ Waiting for app container to be running"
RETRIES=30
until $DC ps app 2>/dev/null | grep -q "Up\|running"; do
    RETRIES=$((RETRIES - 1))
    if [ "$RETRIES" -le 0 ]; then
        echo "ERROR: app container did not start in time"
        exit 1
    fi
    sleep 1
done

echo "→ Running migrations (test schema)"
$DC exec -T app \
    sh -c 'set -a; . /runtime/app.env; set +a; php artisan migrate --force'

echo ""
echo "→ Running tests${FILTER:+ ($FILTER)}${COVERAGE:+ with coverage}"
echo ""

$DC exec -T app \
    sh -c "set -a; . /runtime/app.env; set +a; php artisan test --parallel ${FILTER} ${COVERAGE}"

EXIT_CODE=$?
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo " Tests completed with exit code: ${EXIT_CODE}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
exit $EXIT_CODE
