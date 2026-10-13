#!/bin/bash
# /up answers and every process the entrypoint configured is alive: the local image serves PHP through Caddy +
# php-fpm, the agent image and prod through Octane; all run the scheduler and Horizon. Keyed on the supervisor
# program files, never on APP_ENV: the agent image runs Octane with APP_ENV=local.
# 127.0.0.1, never localhost: localhost may resolve to ::1.
FAILED=0
conf=/etc/supervisor/conf.d
curl -fs -o /dev/null http://127.0.0.1:8080/up || { echo "✗ /up"; FAILED=1; }
[ ! -f $conf/web.conf ] || pgrep -f "artisan octane:frankenphp" >/dev/null || { echo "✗ octane"; FAILED=1; }
[ ! -f $conf/caddy.conf ] || pgrep -x caddy >/dev/null || { echo "✗ caddy"; FAILED=1; }
[ ! -f $conf/php-fpm.conf ] || pgrep -f "php-fpm: master process \(/usr/local/etc/php-fpm.conf\)" >/dev/null || { echo "✗ php-fpm"; FAILED=1; }
# if:spa
[ ! -f $conf/php-fpm-e2e.conf ] || pgrep -f "php-fpm: master process \(/app/docker/php-fpm-e2e.conf\)" >/dev/null || { echo "✗ php-fpm-e2e"; FAILED=1; }
# endif
# unless:spa
# No dev server in API-only. Vite core answers the ping with 204.
# endif
# if:spa
# No dev server before the SvelteKit app exists. Vite core answers the ping with 204.
# endif
[ ! -f $conf/vite.conf ] || curl -fs -o /dev/null -H 'Accept: text/x-vite-ping' http://127.0.0.1:5173/ || { echo "✗ vite"; FAILED=1; }
# if:spa
# SvelteKit's server (prod): /healthz answers on loopback only; Caddy answers it 404.
[ ! -f $conf/ssr.conf ] || curl -fs -o /dev/null http://127.0.0.1:3000/healthz || { echo "✗ ssr"; FAILED=1; }
# endif
pgrep -f "artisan schedule:work" >/dev/null || { echo "✗ scheduler"; FAILED=1; }
pgrep -f "artisan horizon" >/dev/null || { echo "✗ horizon"; FAILED=1; }
# if:reverb
pgrep -f "artisan reverb:start" >/dev/null || { echo "✗ reverb"; FAILED=1; }
# endif
pgrep -x supervisord >/dev/null || { echo "✗ supervisord"; FAILED=1; }
exit $FAILED
