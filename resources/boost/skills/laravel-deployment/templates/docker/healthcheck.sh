#!/bin/bash
# /up answers and every expected process is alive. Local serves PHP through
# nginx + php-fpm, prod through Octane; both run the scheduler and Horizon.
FAILED=0
curl -fs -o /dev/null http://localhost:8080/up || { echo "✗ /up"; FAILED=1; }
if [ "${APP_ENV:-production}" = "local" ]; then
    pgrep -f "nginx: master" >/dev/null || { echo "✗ nginx"; FAILED=1; }
    pgrep -f "php-fpm: master" >/dev/null || { echo "✗ php-fpm"; FAILED=1; }
    curl -fs -o /dev/null http://localhost:5173/up || { echo "✗ vite"; FAILED=1; }
else
    pgrep -f "artisan octane:frankenphp" >/dev/null || { echo "✗ octane"; FAILED=1; }
fi
pgrep -f "artisan schedule:work" >/dev/null || { echo "✗ scheduler"; FAILED=1; }
pgrep -f "artisan horizon" >/dev/null || { echo "✗ horizon"; FAILED=1; }
pgrep -x supervisord >/dev/null || { echo "✗ supervisord"; FAILED=1; }
exit $FAILED
