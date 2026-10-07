# Project files the stack needs

The rendered snippets (SKILL.md, Procedure) are merged into the skeleton's files. This file says why they look as
they do.

| Snippet | Merges into | Modules |
|---|---|---|
| `vite.config.js` | `vite.config.js`: keep its imports and `plugins`; drop `proxy`, `hmr` and `host: '0.0.0.0'` | htmx |
| `phpunit.xml` | `phpunit.xml`: `bootstrap=` and the forced `<env>` list | all |
| `env.dotenv` | `.env`, and `.env.example` with `COMPOSE_PROJECT_NAME` commented | all |
| `config-database.php`, `TestCase-artisan.php` | `config/database.php`, `tests/TestCase.php` (`references/tenancy.md`) | tenancy |
| `hosting-section.md` | `{{hosting}}` in the root `CLAUDE.md` | all |

## vite.config.js: the dev server behind Caddy

Vite runs in the local container on 127.0.0.1:5173. Caddy sends it Vite's dev paths and the HMR socket, and sends
everything else to php-fpm (`docker/Caddyfile.local`). `server.ws` needs Vite 8.1 or later. The HMR client takes its
scheme, host and port from the origin in `public/hot`, so no `clientPort` is set. Keep the anchored `unwatched` paths:
a glob such as `**/docs/**` would also skip `resources/docs`.

## phpunit.xml and tests/bootstrap.php: every `<env>` forced and mirrored

Compose sets `APP_ENV`, `DB_DATABASE` and the drivers as process env in the local container. Process env sits in
`$_SERVER`, and Laravel's `Env` reads `$_SERVER` first. PHPUnit's `<env>` reaches only `$_ENV` and `putenv()`, forced or
not. Without a fix, `php artisan test` in the container runs `RefreshDatabase` on the dev database.

The fix: `tests/bootstrap.php` (a template) copies every `force="true"` value of `phpunit.xml` into `$_SERVER`, and
`phpunit.xml` names it in `bootstrap=`. Never list `DB_HOST`, `DB_PORT` or `REDIS_*`: `.env` (host) or compose
(container) points them at this checkout's own stack.

The same file keeps runs apart. Two runs on one `{{app}}_test`, such as a worker's and its reviewer's in one stack,
deadlock Postgres or wipe each other's rows. So every top-level run takes `storage/framework/testing/db.lock`
exclusively and waits for it. ParaTest's workers (`PARATEST` set) run under their parent's lock.

## Trusted proxies

Laravel trusts the addresses in `TRUSTED_PROXIES`: `config('app.trusted_proxies')`, applied in
`AppServiceProvider::boot()` (laravel-project-setup's `AppServiceProvider-boot.php` snippet). Per tier:

- local: `127.0.0.1`, set in compose (why: SKILL.md, trap "Anyone on the LAN opens `/horizon`");
- prod: the reverse proxy's address as its requests arrive in the container (`.env.prod`), plus `127.0.0.1` with spa
  (`references/spa.md`).

Caddy's own trust (`docker/Caddyfile`) decides only its `{client_ip}`, never what Laravel sees.

## .env and .env.example

The host `.env` is the host-side env: `DB_*` and `REDIS_*` reach the sidecars through their published ports.

## Config and env

- Defaults live in `config/*.php`, as the `env()` default or a plain literal. `.env` files and compose `environment:`
  carry only what differs between tiers: credentials, hosts, `APP_KEY`, `APP_URL`, log level.
- The test for each variable: would two environments ever want different values? No → a config literal.
- `env()` is called only in `config/`, always with a default.
- One name per concern. A second meaning gets a second variable (`REVERB_HOST` connects, `REVERB_SERVER_HOST` listens).
- Fix drift on sight: `.env.example` entries nobody overrides, compose restating framework defaults, `env()` without a
  default, a variable present in one tier's env only.
- `.env.prod.example` lists every required production variable; the prod compose header repeats the list. Prod logs go
  to `stderr` (`docker logs`).
