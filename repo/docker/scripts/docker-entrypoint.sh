#!/bin/sh
# docker/scripts/docker-entrypoint.sh
#
# Entrypoint for app, queue-worker, and scheduler containers.
# Sources generated secrets from the runtime volume before starting the process.
# The runtime volume is populated by the bootstrap container before this runs.

set -e

RUNTIME_CONFIG="/runtime/app.env"

if [ ! -f "$RUNTIME_CONFIG" ]; then
    echo "ERROR: ${RUNTIME_CONFIG} not found."
    echo "       The bootstrap service must run before app containers start."
    echo "       Use: docker compose up --build"
    exit 1
fi

# Export secrets into the container environment.
# Static non-secret config is already set via docker-compose environment: blocks.
# shellcheck disable=SC1090
set -a
. "$RUNTIME_CONFIG"
set +a

exec "$@"
