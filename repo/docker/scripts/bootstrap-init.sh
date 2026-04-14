#!/bin/sh
# docker/scripts/bootstrap-init.sh
#
# Runs inside the bootstrap container (alpine + openssl).
# Generates runtime secrets into the shared Docker named volume /runtime.
# This is for local-development bootstrap only — not the production secret path.
#
# Host requirements: none (runs entirely inside Docker).
# Called automatically by docker compose up --build via depends_on.

set -e

RUNTIME_DIR="/runtime"
CONFIG_FILE="${RUNTIME_DIR}/app.env"
DB_PASS_FILE="${RUNTIME_DIR}/db_password"
REDIS_CONF_FILE="${RUNTIME_DIR}/redis.conf"
CERT_DIR="${RUNTIME_DIR}/certs"

REGENERATE="${REGENERATE:-false}"

if [ -f "$CONFIG_FILE" ] && [ "$REGENERATE" != "true" ]; then
    echo "bootstrap: runtime config already present, skipping generation"
    exit 0
fi

echo "bootstrap: generating runtime secrets..."

# Generate random hex of N bytes using /dev/urandom and od (busybox-safe)
gen_hex() {
    bytes="$1"
    head -c "$bytes" /dev/urandom | od -A n -t x1 | tr -d ' \n' | cut -c1-$(( bytes * 2 ))
}

# Generate base64-encoded key for Laravel APP_KEY (needs openssl for proper base64)
gen_app_key() {
    head -c 32 /dev/urandom | openssl base64 -A
}

APP_KEY="base64:$(gen_app_key)"
DB_PASSWORD="$(gen_hex 32)"
REDIS_PASSWORD="$(gen_hex 32)"
APP_ENCRYPTION_KEY="$(gen_hex 32)"

mkdir -p "$RUNTIME_DIR" "$CERT_DIR"

# ── Internal CA + service certificates ────────────────────────────────────
# Generate a local CA, then issue service certificates for:
#   - nginx  (host-facing HTTPS)
#   - postgres (internal TLS)
#   - redis  (internal TLS)
# All containers mount the CA cert so they can verify each other.

if [ ! -f "${CERT_DIR}/ca.crt" ] || [ "$REGENERATE" = "true" ]; then
    echo "bootstrap: generating internal CA..."
    openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 -nodes \
        -keyout "${CERT_DIR}/ca.key" \
        -out    "${CERT_DIR}/ca.crt" \
        -subj "/CN=ResearchSvc Internal CA/O=ResearchSvc/C=US" \
        2>/dev/null
    chmod 600 "${CERT_DIR}/ca.key"
    echo "bootstrap: internal CA generated"
fi

# Helper: generate a certificate signed by the internal CA
# Usage: gen_service_cert <name> <cn> <san>
gen_service_cert() {
    name="$1"; cn="$2"; san="$3"
    if [ ! -f "${CERT_DIR}/${name}.crt" ] || [ "$REGENERATE" = "true" ]; then
        openssl req -newkey rsa:2048 -sha256 -nodes \
            -keyout "${CERT_DIR}/${name}.key" \
            -out    "${CERT_DIR}/${name}.csr" \
            -subj "/CN=${cn}/O=ResearchSvc/C=US" \
            2>/dev/null
        # Write SAN extension to a temp file (POSIX-safe, no bash process substitution)
        _ext_file="${CERT_DIR}/${name}_ext.cnf"
        printf "subjectAltName=%s\n" "$san" > "$_ext_file"
        openssl x509 -req -sha256 -days 365 \
            -in      "${CERT_DIR}/${name}.csr" \
            -CA      "${CERT_DIR}/ca.crt" \
            -CAkey   "${CERT_DIR}/ca.key" \
            -CAcreateserial \
            -out     "${CERT_DIR}/${name}.crt" \
            -extfile "$_ext_file" \
            2>/dev/null
        rm -f "${CERT_DIR}/${name}.csr" "$_ext_file"
        chmod 600 "${CERT_DIR}/${name}.key"
        echo "bootstrap: ${name} certificate generated"
    fi
}

# nginx / host-facing HTTPS
gen_service_cert "server" "localhost" "DNS:localhost,DNS:app,IP:127.0.0.1"

# PostgreSQL — internal TLS
gen_service_cert "postgres" "postgres" "DNS:postgres,DNS:localhost,IP:127.0.0.1"
# PostgreSQL requires the key file to be owned by the postgres process user
# (uid 70 in postgres:16-alpine) with mode 0600.  The bootstrap container
# runs as root, so we must chown the copy to uid 70.
cp "${CERT_DIR}/postgres.key" "${CERT_DIR}/postgres.key.pg"
chown 70:70 "${CERT_DIR}/postgres.key.pg"
chmod 600 "${CERT_DIR}/postgres.key.pg"
# Cert is public material — ensure postgres user can read it
chmod 644 "${CERT_DIR}/postgres.crt"

# Redis — internal TLS
gen_service_cert "redis" "redis" "DNS:redis,DNS:localhost,IP:127.0.0.1"
# Redis runs as uid 999 in redis:7-alpine; cert + key must be readable by that user.
cp "${CERT_DIR}/redis.key" "${CERT_DIR}/redis.key.rds"
chown 999:999 "${CERT_DIR}/redis.key.rds"
chmod 600 "${CERT_DIR}/redis.key.rds"
# Cert and CA cert are world-readable (public material); key is restricted above.
chmod 644 "${CERT_DIR}/redis.crt" "${CERT_DIR}/ca.crt"

# Write app.env — sourced by docker-entrypoint.sh in app/worker/scheduler containers
cat > "$CONFIG_FILE" <<EOF
APP_KEY=${APP_KEY}
APP_ENCRYPTION_KEY=${APP_ENCRYPTION_KEY}
DB_PASSWORD=${DB_PASSWORD}
REDIS_PASSWORD=${REDIS_PASSWORD}
EOF
chmod 600 "$CONFIG_FILE"

# Write db_password — read by postgres via POSTGRES_PASSWORD_FILE
printf '%s' "$DB_PASSWORD" > "$DB_PASS_FILE"
chmod 600 "$DB_PASS_FILE"

# Write PostgreSQL TLS configuration snippet sourced by the postgres container
cat > "${RUNTIME_DIR}/pg_hba_ssl.conf" <<'EOF'
# Require SSL for all TCP connections (local unix-socket connections are unaffected)
hostssl all all 0.0.0.0/0 scram-sha-256
hostssl all all ::/0      scram-sha-256
EOF
chmod 644 "${RUNTIME_DIR}/pg_hba_ssl.conf"

# Write redis.conf — read by redis-server on startup.
# TLS is enabled for all client connections; plaintext port is disabled.
# Must be world-readable (644) because the redis container runs as the
# unprivileged 'redis' user, not as root.
cat > "$REDIS_CONF_FILE" <<EOF
requirepass ${REDIS_PASSWORD}
bind 0.0.0.0
save ""
loglevel warning
port 0
tls-port 6379
tls-cert-file /runtime/certs/redis.crt
tls-key-file /runtime/certs/redis.key.rds
tls-ca-cert-file /runtime/certs/ca.crt
tls-auth-clients no
EOF
chmod 644 "$REDIS_CONF_FILE"

echo "bootstrap: runtime setup complete"
exit 0
