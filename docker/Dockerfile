# The one place the package is built, tested and looked at: PHP, git, Composer, and a headless Chromium for the UI checks.
# `./dev` builds and runs it. Nothing here is installed on the host.
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli-trixie

RUN apt-get update \
    && apt-get install -y --no-install-recommends git openssh-client unzip procps ca-certificates nodejs npm chromium fonts-liberation fonts-inter python3-fonttools python3-brotli \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" pcntl \
    && printf 'memory_limit=-1\n' > /usr/local/etc/php/conf.d/dev.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Playwright's library only; the browser is the system Chromium above.
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 NODE_PATH=/opt/browser/node_modules CHROMIUM_PATH=/usr/bin/chromium
RUN mkdir -p /opt/browser && cd /opt/browser && npm init -y >/dev/null && npm install --no-audit --no-fund playwright-core

# The invoking user's ids, so files written into the mounted checkout belong to them.
ARG UID=1000
ARG GID=1000
RUN (getent group "$GID" >/dev/null || groupadd -g "$GID" dev) \
    && useradd -m -u "$UID" -g "$GID" -s /bin/bash dev 2>/dev/null || true
ENV HOME=/home/dev COMPOSER_HOME=/home/dev/.composer COMPOSER_CACHE_DIR=/home/dev/.composer/cache
# Mount points of the named volumes `./dev` uses: created owned by that user, a new volume takes the ownership from here.
RUN mkdir -p /app/vendor /home/dev/.composer && chown -R "$UID:$GID" /app /home/dev
WORKDIR /app
