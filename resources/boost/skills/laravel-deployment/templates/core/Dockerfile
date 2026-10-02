# {{app}} — production image. FrankenPHP + Octane, with the scheduler and Horizon
# in the same container under supervisor.

# ── Stage 1: composer ────────────────────────────────────────────────────────
# The composer image lacks pcntl (Horizon requires it); the runtime stage has it
# and checks every platform requirement.
FROM composer:2 AS composer-builder
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist --no-scripts --ignore-platform-req=ext-pcntl

# unless:spa
# ── Stage 2: frontend ────────────────────────────────────────────────────────
# No PHP in this stage: call vite directly, never `npm run build` hooks that
# shell out to artisan. The whole tree and vendor/ are here for Tailwind's @source globs.
FROM node:24-alpine AS node-builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=composer-builder /app/vendor /app/vendor
RUN npx vite build

# endif
# if:spa
# ── Stage 2: frontend ────────────────────────────────────────────────────────
# Debian, never alpine: the runtime copies this stage's node binary and node_modules into a glibc image.
# No PHP here: the validation export is committed, never generated in the build.
FROM node:24-bookworm-slim AS frontend-builder
WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
# Large builds exhaust Node's default heap ("Ineffective mark-compacts near heap limit").
ENV NODE_OPTIONS=--max-old-space-size=8192
# $env/static/public is baked in: the image is built per public URL (compose build.args).
ARG PUBLIC_APP_URL
ENV PUBLIC_APP_URL=$PUBLIC_APP_URL
# adapter-node bundles devDependencies into build/; the runtime keeps only "dependencies" (usually none).
RUN [ -n "$PUBLIC_APP_URL" ] || { echo "PUBLIC_APP_URL build arg missing"; exit 1; }; \
    npm run build && npm ci --omit=dev && mkdir -p node_modules

# endif
# ── Stage 3: runtime ─────────────────────────────────────────────────────────
FROM dunglas/frankenphp:php{{php_version}}
WORKDIR /app
# if:spa
# Node for the ssr program; fails the build early if the binary does not run on this Debian.
COPY --from=frontend-builder /usr/local/bin/node /usr/local/bin/node
RUN node --version
# endif

RUN apt-get update && apt-get install -y --no-install-recommends supervisor curl procps \
    && rm -rf /var/lib/apt/lists/*

# pcntl is load-bearing: Horizon's job timeouts and Octane's SIGTERM need it.
RUN install-php-extensions pcntl pdo_pgsql redis opcache intl

ENV PHP_MEMORY_LIMIT=512M
RUN printf 'memory_limit = ${PHP_MEMORY_LIMIT}\nopcache.enable=1\nopcache.validate_timestamps=0\n' > /usr/local/etc/php/conf.d/zz-{{app}}.ini

COPY . /app
COPY --from=composer-builder /app/vendor /app/vendor
# unless:spa
COPY --from=node-builder /app/public/build /app/public/build
# endif
# if:spa
# frontend/package.json comes with `COPY . /app`; `node build` needs it for "type": "module".
COPY --from=frontend-builder /app/frontend/build /app/frontend/build
COPY --from=frontend-builder /app/frontend/node_modules /app/frontend/node_modules
# endif
RUN --mount=type=bind,from=composer-builder,source=/usr/bin/composer,target=/usr/bin/composer composer check-platform-reqs --no-dev

# Runs as www-data: it owns only what it writes at runtime (Octane writes public/frankenphp-worker.php).
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /data/caddy /config/caddy /etc/supervisor/conf.d \
    && chown -R www-data:www-data storage bootstrap/cache public /data/caddy /config/caddy /etc/supervisor/conf.d

COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY docker/healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/healthcheck.sh

ENV APP_ENV=production APP_DEBUG=false
USER www-data
EXPOSE 8080
# if:reverb
# unless:spa
EXPOSE 8081
# endif
# endif
HEALTHCHECK --interval=30s --timeout=15s --retries=3 --start-period=60s CMD /usr/local/bin/healthcheck.sh
ENTRYPOINT ["docker-entrypoint.sh"]
