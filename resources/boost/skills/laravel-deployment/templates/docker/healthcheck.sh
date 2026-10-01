#!/bin/bash
# /up answers and every expected process is alive. Local serves PHP through
# Caddy + php-fpm, prod through Octane; both run the scheduler and Horizon.
# 127.0.0.1, never localhost: localhost may resolve to ::1.
FAILED=0
curl -fs -o /dev/null http://127.0.0.1:8080/up || { echo "✗ /up"; FAILED=1; }
if [ "${APP_ENV:-production}" = "local" ]; then
    pgrep -x caddy >/dev/null || { echo "✗ caddy"; FAILED=1; }
    pgrep -f "php-fpm: master" >/dev/null || { echo "✗ php-fpm"; FAILED=1; }
    # Only when the entrypoint wrote a dev server: none in API-only, or in spa before frontend/ exists.
    # Vite core answers the ping with 204.
    [ ! -f /etc/supervisor/conf.d/vite.conf ] || curl -fs -o /dev/null -H 'Accept: text/x-vite-ping' http://127.0.0.1:5173/ || { echo "✗ vite"; FAILED=1; }
else
    pgrep -f "artisan octane:frankenphp" >/dev/null || { echo "✗ octane"; FAILED=1; }
    # spa's SvelteKit server (references/spa.md).
    [ ! -f /etc/supervisor/conf.d/ssr.conf ] || curl -fs -o /dev/null http://127.0.0.1:3000/healthz || { echo "✗ ssr"; FAILED=1; }
fi
pgrep -f "artisan schedule:work" >/dev/null || { echo "✗ scheduler"; FAILED=1; }
pgrep -f "artisan horizon" >/dev/null || { echo "✗ horizon"; FAILED=1; }
pgrep -x supervisord >/dev/null || { echo "✗ supervisord"; FAILED=1; }
exit $FAILED
