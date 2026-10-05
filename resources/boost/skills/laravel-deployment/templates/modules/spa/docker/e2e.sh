#!/bin/bash
# spa: Playwright against the test database through the e2e site (http://localhost:8090, docker/Caddyfile.local),
# never the dev data. The only way to run the browser flows (references/spa.md):
#   docker compose -f docker-compose.local.yml exec app docker/e2e.sh [playwright test args]
# Holds storage/framework/testing/db.lock, the one test run at a time: `php artisan test` (tests/bootstrap.php) and a
# second e2e run wait meanwhile, and so does this one.
set -e
cd /app
mkdir -p storage/framework/testing
# fd 9 is inherited through exec: the lock lives exactly as long as Playwright.
exec 9>storage/framework/testing/db.lock
flock -n 9 || { echo "waiting for the test database (another test or e2e run holds it)"; flock 9; }

# The test env is the (e2e_php) snippet of docker/Caddyfile.local, the one list the e2e sites use too.
mapfile -t pairs < <(sed -n '/^(e2e_php)/,/^}/s/^[[:space:]]*env \([A-Z_][A-Z0-9_]*\) \(.*\)$/\1=\2/p' docker/Caddyfile.local)
want="" app_url=""
for p in "${pairs[@]}"; do
    case "$p" in DB_DATABASE=*) want=${p#*=} ;; APP_URL=*) app_url=${p#*=} ;; esac
done
[[ $want == *_test && -n $app_url ]] || { echo "no DB_DATABASE=<name>_test and APP_URL in the (e2e_php) snippet of docker/Caddyfile.local"; exit 1; }
test_env() { env "${pairs[@]}" XDEBUG_MODE=off "$@"; }
curl -fs -o /dev/null http://127.0.0.1:8090/up && curl -fs -o /dev/null http://127.0.0.1:8091/up || { echo "no e2e sites on 127.0.0.1:8090/8091: merge them into docker/Caddyfile.local, then supervisorctl restart app:caddy"; exit 1; }

# A cached config ignores that env, and a DB_URL overrides DB_DATABASE: either way the reset would drop another
# database, so ask the live connection.
[ ! -f bootstrap/cache/config.php ] || { echo "config is cached: php artisan config:clear, then rerun"; exit 1; }
db=$(test_env php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo Illuminate\Support\Facades\DB::selectOne("select current_database() as d")->d;')
[ "$db" = "$want" ] || { echo "refusing: the test env resolves to database '$db', not $want"; exit 1; }

# The image's Chromium belongs to one @playwright/test version (Dockerfile.local).
[ -f frontend/package-lock.json ] || { echo "no frontend/package-lock.json (frontend/CLAUDE.md, First frontend change)"; exit 1; }
want_pw=$(node -p "require('./frontend/package-lock.json').packages['node_modules/@playwright/test']?.version ?? ''")
have_pw=$(cat /ms-playwright/.version 2>/dev/null || true)
[ -n "$want_pw" ] && [ "$want_pw" = "$have_pw" ] || { echo "@playwright/test ${want_pw:-missing} in frontend/package-lock.json, ${have_pw:-none} in the image: docker compose -f docker-compose.local.yml up -d --build"; exit 1; }

# Pest's seed (TestCase #[Seeder]); each Playwright test makes its own data.
# unless:tenancy
test_env php artisan migrate:fresh --seeder=ReferenceDataSeeder --force
# endif
# if:tenancy
# As the tables' owner; the database guard above asks the default connection, which reads the same DB_DATABASE.
test_env php artisan migrate:fresh --database=pgsql_owner --seeder=ReferenceDataSeeder --force
# endif
test_env php artisan cache:clear

cd frontend
# PUBLIC_* values are baked in at build: the browser's origin during the run.
PUBLIC_APP_URL=$app_url npm run build
export E2E_DATABASE_READY=1
exec node_modules/.bin/playwright test "$@"
