#!/bin/bash
# {{app}} local entrypoint: deps on start (sha256-sentinel-guarded), never cache config,
# database wait, migrate, gated seed, then php-fpm + caddy + vite + scheduler + horizon.
set -e
cd /app
echo "=== {{app}} (local) ==="
[ -f .env ] || cp .env.example .env
# sha256 sentinels, not mtimes: `git worktree add` stamps the lockfiles with the
# current time, which would make every new worktree reinstall its copied deps.
lock_sha() { sha256sum "$1" | cut -d' ' -f1; }
# A host cache dir Docker had to create is root-owned; fail with the fix, not an EACCES from npm.
for d in /cache/composer /cache/npm; do
    [ -w "$d" ] || { echo "$d is not writable by uid $(id -u): mkdir -p and chown the host directory to the host user"; exit 1; }
done
[ "$(cat vendor/.lock-sha 2>/dev/null)" = "$(lock_sha composer.lock)" ] || { composer install --no-interaction || { echo "composer install failed"; exit 1; }; lock_sha composer.lock > vendor/.lock-sha; }
# The root app and, with spa, frontend/: each one that has a package.json.
for d in . frontend; do
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
php artisan migrate --force
case "${DATABASE_SEED:-auto}" in
    auto) if php_app 'exit(App\Models\User::query()->exists() ? 0 : 1);'; then php artisan db:seed --class=ReferenceDataSeeder --force; else php artisan db:seed --force; fi ;;
    true) php artisan db:seed --force ;;
    false) ;;
    *) echo "DATABASE_SEED must be auto, true or false, got '${DATABASE_SEED}'"; exit 1 ;;
esac

# exec: the program itself is supervisor's child and gets SIGTERM, so stopwaitsecs is honoured;
# a `cmd | sed` wrapper would die first and the kernel would SIGKILL the real process.
program() { # name command [stopwaitsecs] [directory under /app]
cat > "/etc/supervisor/conf.d/$1.conf" <<CONF
[program:$1]
command=bash -c "exec $2 > >(sed -u 's/^/[$1] /') 2>&1"
directory=/app${4:+/$4}
autostart=true
autorestart=true
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
# laravel-vite-plugin deletes public/hot only on a clean exit; after a SIGKILL or OOM kill Laravel would point at a dead dev server.
rm -f public/hot
# At most one dev server on 127.0.0.1:5173, chosen by what exists. spa: frontend/'s, once frontend/package.json exists.
if [ -f frontend/package.json ]; then
    program vite "node_modules/.bin/vite dev --host 127.0.0.1 --port 5173 --strictPort" 10 frontend
# htmx, islands: the root Vite. API-only deletes this branch with its comment.
elif [ -f package.json ]; then
    program vite "node_modules/.bin/vite --host 127.0.0.1 --port 5173 --strictPort"
fi
program scheduler "php artisan schedule:work"
program horizon "php artisan horizon" 70
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
