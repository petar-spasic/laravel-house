# {{app}} — production image. FrankenPHP + Octane, with the scheduler and Horizon
# in the same container under supervisor.

# ── Stage 1: composer ────────────────────────────────────────────────────────
# The composer image lacks pcntl (Horizon requires it); the runtime stage has it
# and checks every platform requirement.
FROM composer:2 AS composer-builder
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist --no-scripts --ignore-platform-req=ext-pcntl

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

# ── Stage 3: runtime ─────────────────────────────────────────────────────────
FROM dunglas/frankenphp:php{{php_version}}
WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends supervisor curl procps \
    && rm -rf /var/lib/apt/lists/*

# pcntl is load-bearing: Horizon's job timeouts and Octane's SIGTERM need it.
RUN install-php-extensions pcntl pdo_pgsql redis opcache intl

ENV PHP_MEMORY_LIMIT=512M
RUN printf 'memory_limit = ${PHP_MEMORY_LIMIT}\nopcache.enable=1\nopcache.validate_timestamps=0\n' > /usr/local/etc/php/conf.d/zz-{{app}}.ini

COPY . /app
COPY --from=composer-builder /app/vendor /app/vendor
COPY --from=node-builder /app/public/build /app/public/build
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
HEALTHCHECK --interval=30s --timeout=15s --retries=3 --start-period=60s CMD /usr/local/bin/healthcheck.sh
ENTRYPOINT ["docker-entrypoint.sh"]
