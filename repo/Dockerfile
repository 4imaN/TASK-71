# ──────────────────────────────────────────────────────────────────────────────
# Stage: frontend — compiles CSS/JS assets with Vite + Tailwind CSS v4
#
# Runs npm install + vite build in an isolated Node image so the PHP app
# image never requires Node at runtime.  The compiled public/build/ directory
# is copied into both the app and app-dev stages below.
#
# No package-lock.json is committed; npm install resolves the semver ranges
# declared in package.json.  Tailwind scans resources/ for class names.
# ──────────────────────────────────────────────────────────────────────────────
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package.json ./
RUN npm install --no-audit
COPY vite.config.js ./
COPY resources/ ./resources/
RUN npm run build

# ──────────────────────────────────────────────────────────────────────────────
# Stage: bootstrap
# Generates runtime secrets + TLS cert into a named Docker volume.
# Runs automatically before all other services via depends_on condition.
# No secrets are hardcoded here; all values are produced at container runtime.
# ──────────────────────────────────────────────────────────────────────────────
FROM alpine:3.20 AS bootstrap
RUN apk add --no-cache ca-certificates openssl
COPY docker/scripts/bootstrap-init.sh /bootstrap-init.sh
RUN chmod +x /bootstrap-init.sh
CMD ["/bootstrap-init.sh"]

# ──────────────────────────────────────────────────────────────────────────────
# Stage: base — PHP 8.3 on Alpine 3.20
#
# Alpine version is pinned to 3.20 so the image tracks PostgreSQL 16, matching
# the postgres:16-alpine service in docker-compose.yml.  The :alpine (floating)
# tag currently resolves to Alpine 3.23 / PostgreSQL 18, whose dev package
# pulls llvm20 (170 MB+), which consistently causes I/O errors and
# integrity failures during extraction on clean Docker Desktop installs.
#
# LLVM elimination strategy
# ─────────────────────────
# Both Alpine 3.20 (llvm16) and 3.23 (llvm20) ship postgresql-dev with JIT
# support, which drags LLVM into the build.  We avoid postgresql-dev entirely:
#
#   1. postgresql-client (runtime layer) ships pg_config — the binary that
#      PHP's pdo_pgsql/pgsql configure scripts use to locate libraries.
#   2. libpq-dev (build layer) provides only the client-library headers.
#      It has NO dependency on LLVM.
#
# This gives php's ./configure everything it needs (pg_config + libpq-fe.h)
# without the hundreds of MB of JIT toolchain.
#
# GD is intentionally excluded: no image-manipulation requirements exist in
# the scaffold.  Adding it would reintroduce ~60 extra packages.
# ──────────────────────────────────────────────────────────────────────────────
FROM php:8.3-fpm-alpine3.20 AS base

# 1. Refresh CA bundle before any network operations inside the build.
RUN apk add --no-cache ca-certificates && update-ca-certificates

# 2. Runtime packages.
#    postgresql-client → libpq (runtime .so) + pg_config (used by build below).
#    zip/unzip — Composer package extraction.
RUN apk add --no-cache \
    postgresql-client \
    zip \
    unzip

# 3. Build PHP extensions.
#    All compiler/header packages go into .build-deps and are removed at the
#    end of this layer — they never appear in child images.
#
#    libpq-dev: client-library headers needed by pdo_pgsql/pgsql configure.
#               Does NOT pull LLVM (only postgresql-dev does).
#    pg_config is already in PATH from step 2 (postgresql-client).
#
#    redis-6.1.0: pinned PECL release for reproducible builds.
RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        autoconf \
        g++ \
        gcc \
        libc-dev \
        make \
        pkgconf \
        re2c \
        linux-headers \
        libpq-dev \
    ; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        pcntl \
        opcache \
        bcmath \
    ; \
    pecl install redis-6.1.0; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /var/cache/apk/* /tmp/pear ~/.pearrc

COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer

# ──────────────────────────────────────────────────────────────────────────────
# Stage: app — production image
# ──────────────────────────────────────────────────────────────────────────────
FROM base AS app

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN rm -f .env .env.example .env.local .env.test .env.testing \
       bootstrap/cache/packages.php bootstrap/cache/services.php

RUN composer dump-autoload --optimize --no-dev \
  && php artisan config:clear \
  && php artisan route:clear \
  && php artisan view:clear \
  && chown -R www-data:www-data storage bootstrap/cache \
  && chmod -R 775 storage bootstrap/cache

COPY docker/scripts/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

# ──────────────────────────────────────────────────────────────────────────────
# Stage: app-dev — same base + dev Composer deps (used by run_tests.sh)
# ──────────────────────────────────────────────────────────────────────────────
FROM base AS app-dev

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader --no-scripts --no-interaction

COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN rm -f .env .env.example .env.local .env.test .env.testing \
       bootstrap/cache/packages.php bootstrap/cache/services.php \
  && chown -R www-data:www-data storage bootstrap/cache \
  && chmod -R 775 storage bootstrap/cache

COPY docker/scripts/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
