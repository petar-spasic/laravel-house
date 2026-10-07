#!/bin/bash
# {{app}} local entrypoint: deps on start (sha256-sentinel-guarded), never cache config,
# database wait, migrate, reference data and gated account seed, then php-fpm + caddy + vite + scheduler + horizon.
set -e
cd /app
echo "=== {{app}} (local) ==="
[ -f .env ] || cp .env.example .env
# if:spa
# Sanctum's stateful origins are compose's localhost and 127.0.0.1 at WEB_PORT plus APP_URL's host (LOCAL_APP_URL).
# `php artisan` through `docker compose exec` sees only compose's part; CLI requests are never stateful.
export SANCTUM_STATEFUL_DOMAINS="$SANCTUM_STATEFUL_DOMAINS,$(echo "${APP_URL#*://}" | cut -d/ -f1)"
# SvelteKit answers a fetch to its own origin itself: API_INTERNAL_URL is never a page's origin.
case ",$SANCTUM_STATEFUL_DOMAINS," in *",${API_INTERNAL_URL#*://},"*) echo "the web port is 8080, which is API_INTERNAL_URL's: set another WEB_PORT"; exit 1 ;; esac
# endif
# sha256 sentinels, not mtimes: `git worktree add` stamps the lockfiles with the
# current time, which would make every new worktree reinstall its copied deps.
lock_sha() { sha256sum "$1" | cut -d' ' -f1; }
# A host cache dir Docker had to create is root-owned; fail with the fix, not an EACCES from npm.
for d in /cache/composer /cache/npm; do
    [ -w "$d" ] || { echo "$d is not writable by uid $(id -u): mkdir -p and chown the host directory to the host user"; exit 1; }
done
[ "$(cat vendor/.lock-sha 2>/dev/null)" = "$(lock_sha composer.lock)" ] || { composer install --no-interaction || { echo "composer install failed"; exit 1; }; lock_sha composer.lock > vendor/.lock-sha; }
# unless:spa
for d in .; do
# endif
# if:spa
# The root app and frontend/: each one that has a package.json.
for d in . frontend; do
# endif
    [ -f "$d/package.json" ] || continue
    [ -f "$d/package-lock.json" ] || { echo "$d/package.json has no package-lock.json: run npm install in $d"; exit 1; }
    [ "$(cat "$d/node_modules/.lock-sha" 2>/dev/null)" = "$(lock_sha "$d/package-lock.json")" ] || { (cd "$d" && npm ci) || { echo "npm ci in $d failed"; exit 1; }; lock_sha "$d/package-lock.json" > "$d/node_modules/.lock-sha"; }
done
grep -q '^APP_KEY=.\+' .env || php artisan key:generate --force
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover --ansi
php artisan config:clear && php artisan route:clear && php artisan event:clear && php artisan view:clear

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
case "${DATABASE_SEED:-auto}" in
    auto) if php_app 'exit(App\Models\User::query()->exists() ? 0 : 1);'; then php artisan db:seed --class=ReferenceDataSeeder --force; else php artisan db:seed --force; fi ;;
    true) php artisan db:seed --force ;;
    false) php artisan db:seed --class=ReferenceDataSeeder --force ;;
    *) echo "DATABASE_SEED must be auto, true or false, got '${DATABASE_SEED}'"; exit 1 ;;
esac

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
program php-fpm "php-fpm -F"
program caddy "caddy run --config /app/docker/Caddyfile.local --adapter caddyfile"
# unless:spa
# laravel-vite-plugin deletes public/hot only on a clean exit; after a SIGKILL or OOM kill Laravel would point at a dead dev server.
rm -f public/hot
# endif
# if:spa
# SvelteKit's dev server on 127.0.0.1:5173, once frontend/package.json exists.
if [ -f frontend/package.json ]; then
    program vite "node_modules/.bin/vite dev --host 127.0.0.1 --port 5173 --strictPort" 10 frontend
fi
# endif
# if:htmx
# The root Vite on 127.0.0.1:5173.
program vite "node_modules/.bin/vite --host 127.0.0.1 --port 5173 --strictPort"
# endif
program scheduler "php artisan schedule:work"
program horizon "php artisan horizon" 70
# if:reverb
# Listens on REVERB_SERVER_* (compose).
program reverb "php artisan reverb:start"
# endif

# Last, after every program: supervisord stops one group after another, but a group's programs together, so the stop
# takes the longest stopwaitsecs, not their sum. supervisorctl names them app:caddy, app:horizon, …
printf '[group:app]\nprograms=%s\n' "$(cd /etc/supervisor/conf.d && ls *.conf | sed 's/\.conf$//' | paste -sd,)" > /etc/supervisor/conf.d/zz-group.conf
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
