#!/bin/bash
# {{app}} local entrypoint: deps on start (sha256-sentinel-guarded), never cache config,
# database wait, migrate, gated seed, then php-fpm + nginx + vite + scheduler + horizon.
set -e
cd /app
echo "=== {{app}} (local) ==="
[ -f .env ] || cp .env.example .env
# sha256 sentinels, not mtimes: `git worktree add` stamps the lockfiles with the
# current time, which would make every new worktree reinstall its copied deps.
lock_sha() { sha256sum "$1" | cut -d' ' -f1; }
[ "$(cat vendor/.lock-sha 2>/dev/null)" = "$(lock_sha composer.lock)" ] || { composer install --no-interaction && lock_sha composer.lock > vendor/.lock-sha; }
[ "$(cat node_modules/.lock-sha 2>/dev/null)" = "$(lock_sha package-lock.json)" ] || { npm ci && lock_sha package-lock.json > node_modules/.lock-sha; }
grep -q '^APP_KEY=.\+' .env || php artisan key:generate --force
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover --ansi
php artisan config:clear && php artisan route:clear && php artisan event:clear && php artisan view:clear

# Plain bootstrap: tinker's --execute does not propagate exit codes.
php_app() {
    php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '"$1"
}
echo "waiting for database..."
for i in $(seq 1 60); do php_app 'try { Illuminate\Support\Facades\DB::connection()->getPdo(); exit(0); } catch (Throwable) { exit(1); }' && break; [ "$i" = 60 ] && { echo "database unreachable"; exit 1; }; sleep 2; done
# Once and loudly: a broken migration stops the boot with its own error.
php artisan migrate --force
case "${DATABASE_SEED:-auto}" in
    auto) if php_app 'exit(App\Models\User::query()->exists() ? 0 : 1);'; then php artisan db:seed --class=ReferenceDataSeeder --force; else php artisan db:seed --force; fi ;;
    true) php artisan db:seed --force ;;
    false) ;;
    *) echo "DATABASE_SEED must be auto, true or false, got '${DATABASE_SEED}'"; exit 1 ;;
esac

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

program php-fpm "php-fpm -F"
program nginx "nginx -g 'daemon off;'"
program vite "node_modules/.bin/vite --port 5173 --strictPort"
program scheduler "php artisan schedule:work"
program horizon "php artisan horizon" 70
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
