#!/bin/bash
# {{app}} prod entrypoint: manifest rebuild, database wait, migrate, gated seed,
# cache, then Octane + scheduler + Horizon under supervisor.
set -e
cd /app
echo "=== {{app}} starting ==="
printenv | sort | sed -E 's/^([^=]*(PASSWORD|SECRET|KEY|TOKEN)[^=]*=).+$/\1********/' | grep -v '^_'

mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# Never trust a bootstrap/cache from another image: a stale manifest drops providers.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover --ansi

# Plain bootstrap: tinker's --execute does not propagate exit codes.
php_app() {
    php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '"$1"
}
echo "waiting for database..."
for i in $(seq 1 60); do php_app 'try { Illuminate\Support\Facades\DB::connection()->getPdo(); exit(0); } catch (Throwable) { exit(1); }' && break; [ "$i" = 60 ] && { echo "database unreachable"; exit 1; }; sleep 2; done
# Once and loudly: a broken migration stops the boot with its own error.
php artisan migrate --force
# ProductionSeeder only: the prod image has no faker, so DatabaseSeeder's factories
# would crash-loop the container (database/CLAUDE.md).
case "${DATABASE_SEED:-false}" in
    true) php artisan db:seed --class=ProductionSeeder --force ;;
    false) ;;
    *) echo "DATABASE_SEED must be true or false in prod, got '${DATABASE_SEED}'"; exit 1 ;;
esac

php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

program() { # name command [stopwaitsecs]
cat > "/etc/supervisor/conf.d/$1.conf" <<CONF
[program:$1]
command=bash -c "$2 2>&1 | sed -u 's/^/[$1] /'"
directory=/app
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stopwaitsecs=${3:-10}
CONF
}

program web "php artisan octane:frankenphp --host=0.0.0.0 --port=8080 --workers=${OCTANE_WORKERS:?OCTANE_WORKERS unset in .env.prod} --log-level=${OCTANE_LOG_LEVEL:-INFO} --caddyfile=/app/docker/Caddyfile"
program scheduler "php artisan schedule:work"
# Horizon owns the worker pool (config/horizon.php). stopwaitsecs covers its job
# timeout (60 s) so an in-flight job finishes on SIGTERM; the compose
# stop_grace_period stays above it.
program horizon "php artisan horizon" 70

echo "=== supervisord ==="
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
