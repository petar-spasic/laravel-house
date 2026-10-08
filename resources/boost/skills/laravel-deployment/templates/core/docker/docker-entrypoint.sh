#!/bin/bash
# {{app}} prod entrypoint: manifest rebuild, database wait, migrate, reference data, gated account seed,
# cache, then Octane + scheduler + Horizon under supervisor.
set -e
cd /app
echo "=== {{app}} starting ==="
# Variable names only: values carry credentials in too many shapes (URLs, DSNs, multi-line keys) to mask.
echo "env: $(env -0 | while IFS= read -r -d '' kv; do echo "${kv%%=*}"; done | grep -v '^_' | sort | tr '\n' ' ')"
# A number: Octane's `auto` (one worker per core) is not sized to the box's RAM. FrankenPHP reads 0, 00 and the
# like as auto, so a leading zero is refused too.
case "${OCTANE_WORKERS:?OCTANE_WORKERS unset in .env.prod}" in
    ''|*[!0-9]*|0*) echo "OCTANE_WORKERS must be a positive whole number without a leading zero, never auto, got '${OCTANE_WORKERS}'"; exit 1 ;;
esac
# Empty leaves the reverse proxy untrusted: every client shares its address and URLs come out http://.
[ -n "${TRUSTED_PROXIES:-}" ] || { echo "TRUSTED_PROXIES is empty: set the reverse proxy's address as requests arrive in the container"; exit 1; }
# if:spa
# APP_URL is also the SvelteKit server's ORIGIN: adapter-node refuses an empty one and, without one, assumes https
# and 403s every form post.
case "${APP_URL:-}" in
    http://*/*|https://*/*) echo "APP_URL must be the site's bare origin, no path or trailing slash (https://host), got '${APP_URL}'"; exit 1 ;;
    http://?*|https://?*) ;;
    *) echo "APP_URL must be the site's bare origin (https://host), got '${APP_URL:-}'"; exit 1 ;;
esac
# Hosting lists every public origin (config/sanctum.php adds APP_URL's host): empty means .env.prod was never filled.
[ -n "${SANCTUM_STATEFUL_DOMAINS:-}" ] || { echo "SANCTUM_STATEFUL_DOMAINS is empty: set the public host"; exit 1; }
# SvelteKit's server-side calls come from 127.0.0.1, browsers through the reverse proxy: Laravel trusts both.
case ",${TRUSTED_PROXIES// /}," in *,127.0.0.1,*) ;; *) echo "TRUSTED_PROXIES must include 127.0.0.1 (SvelteKit's server-side calls)"; exit 1 ;; esac
[ "$(echo "${TRUSTED_PROXIES// /}" | tr ',' '\n' | grep -cvxE '127\.0\.0\.1|')" -gt 0 ] || { echo "TRUSTED_PROXIES also needs the reverse proxy's address as its requests arrive in the container"; exit 1; }
# The e2e site's switch: it lifts every rate limiter.
case "${APP_E2E:-false}" in false|0|'') ;; *) echo "APP_E2E is for the local e2e site only, got '${APP_E2E}'"; exit 1 ;; esac
# endif

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
for i in $(seq 1 60); do
    php_app 'try { Illuminate\Support\Facades\DB::connection()->getPdo(); exit(0); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }' 2>/tmp/db-wait.err && break
    [ "$i" = 60 ] && { echo "database unreachable: $(tr '\n' ' ' </tmp/db-wait.err)"; exit 1; }
    sleep 2
done
# Once and loudly: a broken migration stops the boot with its own error.
# unless:tenancy
php artisan migrate --force
# endif
# if:tenancy
# As the tables' owner; the app's role cannot create them.
php artisan migrate --force --database=pgsql_owner
# endif
# Reference data on every boot: a release's new rows must exist before the app serves them. Idempotent, no faker.
php artisan db:seed --class=ReferenceDataSeeder --force
# The accounts: ProductionSeeder, never DatabaseSeeder, whose factories need faker, which the prod image lacks
# (database/CLAUDE.md).
case "${DATABASE_SEED:-false}" in
    true) php artisan db:seed --class=ProductionSeeder --force ;;
    false) ;;
    *) echo "DATABASE_SEED must be true or false in prod, got '${DATABASE_SEED}'"; exit 1 ;;
esac

php artisan config:cache
php artisan route:cache
php artisan event:cache
# unless:spa
php artisan view:cache
# endif
# if:spa
# resources/views may not exist (git keeps no empty directory), and view:cache fails on a missing view path.
[ ! -d resources/views ] || php artisan view:cache
# endif

# exec: the program itself is supervisor's child and gets SIGTERM, so stopwaitsecs is honoured;
# a `cmd | sed` wrapper would die first and the kernel would SIGKILL the real process. startretries: supervisor's 3
# leave Horizon FATAL through a Redis restart.
program() { # name command [stopwaitsecs] [directory under /app]
cat > "/etc/supervisor/conf.d/$1.conf" <<CONF
[program:$1]
command=bash -c "exec $2 > >(sed -u 's/^/[$1] /') 2>&1"
directory=/app${4:+/$4}
autostart=true
autorestart=true
startsecs=5
startretries=20
stopasgroup=false
killasgroup=true
redirect_stderr=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stopwaitsecs=${3:-10}
CONF
}

# conf.d survives `docker compose restart`: start from the programs this boot configures.
rm -f /etc/supervisor/conf.d/*.conf
program web "php artisan octane:frankenphp --host=0.0.0.0 --port=8080 --workers=${OCTANE_WORKERS} --log-level=${OCTANE_LOG_LEVEL:-WARN} --caddyfile=/app/docker/Caddyfile" 30
# if:spa
# SvelteKit (adapter-node) on loopback; Caddy is its only client and sets X-Real-IP. ORIGIN fixes event.url, so
# PROTOCOL_HEADER and HOST_HEADER are unused. SHUTDOWN_TIMEOUT (20) < stopwaitsecs (25) < compose stop_grace_period
# (80); the app group stops it together with the others. BODY_SIZE_LIMIT = PHP's post_max_size: raise both together.
# Literals, not .env.prod entries: no two environments differ in them.
program ssr "env HOST=127.0.0.1 PORT=3000 ORIGIN=${APP_URL%/} ADDRESS_HEADER=X-Real-IP BODY_SIZE_LIMIT=8M SHUTDOWN_TIMEOUT=20 API_INTERNAL_URL=http://127.0.0.1:8080 node build" 25 frontend
# endif
program scheduler "php artisan schedule:work"
# Horizon owns the worker pool (config/horizon.php). stopwaitsecs covers its job
# timeout (60 s) so an in-flight job finishes on SIGTERM; the compose
# stop_grace_period stays above it.
program horizon "php artisan horizon" 70
# if:reverb
# Listens on REVERB_SERVER_* (.env.prod).
program reverb "php artisan reverb:start"
# endif

# Last, after every program: supervisord stops one group after another, but a group's programs together, so the stop
# takes the longest stopwaitsecs, not their sum. supervisorctl names them app:web, app:horizon, …
printf '[group:app]\nprograms=%s\n' "$(cd /etc/supervisor/conf.d && ls *.conf | sed 's/\.conf$//' | paste -sd,)" > /etc/supervisor/conf.d/zz-group.conf

echo "=== supervisord ==="
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
