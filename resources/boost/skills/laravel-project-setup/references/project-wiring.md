# Project wiring

The facts the generated rules state about every project, and how setup makes each one true (SKILL.md step 6).
Snippets are the rendered copies under `<dir>/snippets/` (SKILL.md step 4).

## Tests

- `tests/E2E/` is the only test directory. The installer wrote four flows: Fortify's endpoints, Horizon access,
  seeding, two-factor.
- `phpunit.xml` has one testsuite, `E2E`, on `tests/E2E`. Its `<php>` block and `tests/bootstrap.php` are
  laravel-deployment's (step 9).
- `tests/Pest.php` holds `pest()->extend(TestCase::class)->in('E2E');` and nothing else.
- `tests/TestCase.php` carries `#[Seeder(ReferenceDataSeeder::class)]`. With htmx, its `setUp()` also calls
  `$this->withoutVite()`.
- With tenancy, `TestCase` also migrates through the owner connection: laravel-deployment `references/tenancy.md`.

## Postgres and Redis everywhere

- Config defaults: `pgsql` in `config/database.php` and in `config/queue.php` (`batching`, `failed`); `redis` in
  `config/queue.php`, `config/cache.php` and `config/session.php`.
- `.env` and `.env.example` drop `DB_CONNECTION`, `SESSION_DRIVER`, `QUEUE_CONNECTION` and `CACHE_STORE`.
- They point at the sidecars: `APP_URL=http://localhost:{{web_port}}`, `DB_HOST=127.0.0.1`, `DB_PORT={{db_port}}`,
  `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` all `{{app}}`, `REDIS_PORT={{redis_port}}`.
- `APP_NAME={{app_name}}`. The session cookie is named after it, so two apps on one host under the skeleton's
  `Laravel` log each other out.

## Seeding

- The installer wrote the four seeders and `database/data/.gitkeep` (`database/CLAUDE.md`).
- Merge `config-auth.php` into `config/auth.php`: the `admins` and `operator` keys. A missing key closes the Horizon
  gate silently.
- `.env` gets `ADMIN_EMAILS=admin@{{app}}.test`. `.env.example` documents it, commented.
- Check: `php artisan tinker --execute="var_export(config('auth.admins'));"` lists the `ADMIN_EMAILS` addresses.
- `ProductionSeeder` seeds the operator and each `ADMIN_EMAILS` address (`database/CLAUDE.md`).

## Horizon

- The installer wrote `app/Providers/HorizonServiceProvider.php`: the `viewHorizon` gate, and an `authorization()`
  that admits local requests only from the host itself.
- `routes/console.php` gets
  `Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping();`. Without it the dashboard's
  metrics stay blank.

## .gitignore

Add `/.claude/settings.local.json` and `.env.prod`. The skeleton covers `.env.production` only.
