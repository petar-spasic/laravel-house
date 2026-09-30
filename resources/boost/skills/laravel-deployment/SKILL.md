---
name: laravel-deployment
description: >-
  House hosting for a Laravel app in Docker: a local image (nginx + php-fpm +
  Xdebug, Vite as the front door, bind-mounted source) and a production image
  (FrankenPHP + Octane, non-root), each running the web server, the scheduler
  and Horizon under supervisor next to Postgres and Redis sidecars. Ships the
  Dockerfiles, entrypoints, compose files, healthcheck, Caddyfile and the
  nginx, Xdebug and supervisor configs as templates, plus the boot order, the
  queue timeout chain, the local compose that serves many worktree stacks, the
  Vite front door, forced phpunit env, and the traps behind a container that
  boots but serves nothing. Reverb and SPA serving are modules in references/.
  Use when dockerizing a Laravel project, writing or debugging its Dockerfiles,
  compose files or entrypoints, wiring Octane, FrankenPHP, Horizon, the
  scheduler or Reverb into one image, or filling the Hosting section of the
  project's CLAUDE.md. Triggers — Laravel Docker, docker-compose Laravel,
  FrankenPHP, Octane deploy, Reverb deploy, supervisord Laravel, Laravel
  entrypoint, Laravel production hosting.
---

# Laravel deployment

One repo ships two images of one stack. Each runs the web server, `schedule:work` and Horizon under supervisor in one
app container, next to Postgres 16 and Redis 7 sidecars; only what serves PHP differs:

| Tier | Files | Web server | Why |
|---|---|---|---|
| local | `Dockerfile.local`, `docker-compose.local.yml` | nginx + php-fpm + Xdebug; Vite fronts the web port | A fresh PHP process per request, so Xdebug breakpoints fire (under Octane only a worker's first request breaks). Source bind-mounted, deps installed on start |
| prod | `Dockerfile`, `docker-compose.yml`, `.env.prod` | FrankenPHP + Octane as www-data on :8080 | Warm workers. TLS ends at the host's reverse proxy |

The Docker local stack is the dev environment: no `artisan serve`, no `composer run dev`, no sqlite, no driver that
differs between the tiers.

## Templates

`${CLAUDE_SKILL_DIR}/templates/` mirrors the project root:

| Template | Carries |
|---|---|
| `Dockerfile` | composer stage (ignores only `ext-pcntl`) → node stage (whole tree + `vendor/`, for Tailwind's `@source`) → runtime: extensions, `composer check-platform-reqs`, `USER www-data` |
| `Dockerfile.local` | php-fpm + nginx + NodeSource 24 + git/openssh-client/unzip + Xdebug; www-data re-homed to `HOST_UID`/`HOST_GID` |
| `docker-compose.local.yml` | the many-stacks shape, hardcoded wiring and drivers, `LOCAL_APP_URL`, host cache binds |
| `docker-compose.yml` | loopback publish, `restart`, log rotation, Redis `noeviction` + AOF, `stop_grace_period` |
| `docker/docker-entrypoint.sh`, `docker/docker-entrypoint-local.sh` | the boot order below; `program()` writes one supervisor block per process |
| `docker/healthcheck.sh` | `/up` plus every process of the tier |
| `docker/supervisord.conf` | non-root, socket and pid in `/tmp`, the mandatory `[include]` |
| `docker/Caddyfile` | Blade apps: files off disk (`/build/*` immutable), everything else to the worker; proxy trust |
| `docker/nginx-local.conf`, `docker/xdebug.ini` | `HTTP_HOST` with its port; Xdebug on trigger |
| `docker/postgres/init-test-db.sql` | `{{app}}_test` on a fresh volume |
| `tests/bootstrap.php` | forced phpunit `<env>` mirrored into `$_SERVER` |
| `.dockerignore`, `.env.prod.example` | the build context (never `public/hot`, `vendor/` or the FrankenPHP binary); the production env |

Placeholders: `{{app}}` the project slug · `{{app_name}}` its `APP_NAME` · `{{php_version}}` the one PHP minor of
host, lock and both images · `{{web_port}}`, `{{db_port}}`, `{{redis_port}}` main's host ports · `{{domain}}` the
production host name. The PHP minor and the ports are laravel-project-setup's choices.

1. A template the project lacks: copy it and fill the placeholders. A file the project has: diff it against the
   template and merge, keeping what is project-specific. Show the owner either way.
2. `chmod +x docker/*.sh`; `grep -rn '{{' Dockerfile* docker-compose*.yml docker .dockerignore .env.prod.example`
   finds nothing.
3. Merge `references/project-files.md` into `vite.config.js`, `phpunit.xml` (+ `tests/bootstrap.php`), `bootstrap/app.php`, `.env` and
   `.env.example`.
4. Modules: `reverb` → `references/reverb.md`; `spa`, or API-only without a Vite front door → `references/spa.md`.
5. Fill `{{hosting}}` in the root `CLAUDE.md` from `references/hosting-section.md`, adjusted to what was built.
6. Verify (below).

## Boot order

Both entrypoints, in this order:

1. Local: `.env` from `.env.example` when missing; `composer install` / `npm ci` only when the lockfile's sha256 differs
   from `vendor/.lock-sha` / `node_modules/.lock-sha` (never mtimes: `git worktree add` stamps lockfiles); `key:generate`
   when `APP_KEY` is empty. Prod: print the env variable names, never values; refuse an `OCTANE_WORKERS` that is not a positive number.
2. Prod: create the `storage/` and `bootstrap/cache` directories the volume may lack.
3. `rm bootstrap/cache/{packages,services}.php` + `package:discover`: a manifest from another image or branch drops
   providers, and `optimize:clear` cannot help because artisan itself fails to boot.
4. Local: `config:clear`, `route:clear`, `event:clear`, `view:clear`; never cache locally.
5. Wait for the database with `php_app`, a plain `php -r` bootstrap around `DB::connection()->getPdo()` (tinker swallows
   exit codes): 60 × 2 s, then "database unreachable" and exit 1. The wait only connects.
6. `migrate --force`, once and loudly: a broken migration stops the boot with its own error.
7. Seed by `DATABASE_SEED`. Local: `auto` (full seed when `users` is empty, else `ReferenceDataSeeder`), `true`, `false`.
   Prod: `true` (`ProductionSeeder` only; the image has no faker), `false`. Anything else exits 1. The seeders are
   idempotent (laravel-project-setup's seeding standard), so a second boot never crash-loops.
8. Prod: `config:cache`, `route:cache`, `event:cache`, `view:cache`, after migrating.
9. `program` blocks (prod: web; local: php-fpm, nginx, vite; both: scheduler, horizon with `stopwaitsecs` 70; each `exec`s its
   command, so SIGTERM reaches the real process and `stopwaitsecs` is honoured), then `exec supervisord -n`. Healthy therefore means migrated and seeded.

## Processes and limits

- Horizon owns the worker pool (`config/horizon.php`); never `queue:work`. `pcntl` is in both images: Horizon needs it,
  and job timeouts and graceful SIGTERM depend on it.
- Timeout chain: Redis `retry_after` (`REDIS_QUEUE_RETRY_AFTER`, 90) > Horizon's `timeout` (60) > the longest job's
  `$timeout`; supervisor `stopwaitsecs` for Horizon (70) > that timeout; compose `stop_grace_period` (80) >
  `stopwaitsecs`. A longer job raises all of them, in that order; otherwise a running job is dispatched to a second
  worker mid-run.
- `OCTANE_WORKERS` is a number, never `auto` (one per core): workers × `PHP_MEMORY_LIMIT` (512M), plus Horizon's
  processes, fits the box's RAM. The prod entrypoint refuses to start without it.
- One Redis holds queue, cache and sessions: `noeviction` and AOF in prod, so memory pressure fails writes instead of
  dropping jobs and sessions.

## Many stacks from one local compose

The local compose runs as main and as every worktree stack (laravel-kanban):

- `name: "${COMPOSE_PROJECT_NAME:?…}"`: main's `.env` names `{{app}}-local`; a checkout without its own `.env` fails
  instead of taking over main's containers.
- No `container_name`, no volume or network `name:`, no `image:` on a built service.
- Every published port is a `${VAR:-default}`; the web port binds `${WEB_BIND:-0.0.0.0}` (IPv4 explicitly, see the
  Horizon trap), the sidecars `${SIDECAR_BIND:-127.0.0.1}`.
- Tool caches are host bind mounts (`down -v` deletes named volumes), dependency sentinels hash the lockfiles, Vite
  ignores `./.claude/worktrees`, `./docs` and `./vendor`, and `phpunit.xml` never sets `DB_HOST`/`DB_PORT`.

The generated worktree `.env`, the port pool and Docker address pools: the laravel-kanban README, "Worktree stacks".

## Config and env

- Defaults live in `config/*.php`, as the `env()` default or a plain literal. `.env` files and compose `environment:`
  carry only what differs between tiers: credentials, hosts, `APP_KEY`, `APP_URL`, log level. The test for each
  variable: would two environments ever want different values? No → a config literal.
- `env()` is called only in `config/`, always with a default; `bootstrap/app.php`'s `TRUSTED_PROXIES` is the one
  exception (it runs before config loads).
- One name per concern: a second meaning gets a second variable (`REVERB_HOST` connect, `REVERB_SERVER_HOST` listen).
- Fix drift on sight: `.env.example` entries nobody overrides, compose restating framework defaults, `env()` without a
  default, a variable present in one tier's env only.
- `.env.prod.example` lists every required production variable; the prod compose header repeats the list. Logs go to
  `stderr` in prod (`docker logs`).

## Traps

- **supervisord aborts ("… is badly formatted"), or a command runs mangled** → a `%` in a `program` command is a format
  specifier: `%Y` and `%d` abort, `%s` splices the whole expansion dict (environment included) into the command; write
  `%%`.
- **Container runs, zero services** → `supervisord.conf` lacks `[include] files = /etc/supervisor/conf.d/*.conf`.
- **Providers missing after an image update** → a stale `bootstrap/cache` manifest; persist `storage/` only, never
  `bootstrap/cache`, and let the entrypoint rebuild the manifest.
- **Horizon refuses to start, hung jobs never time out, stops are not graceful** → `pcntl` missing from the image.
- **A `COPY` line with a trailing `# comment` fails or copies junk** → Dockerfile comments go on their own line.
- **The page loads from another machine but every asset fails with `ERR_CONNECTION_REFUSED`** → `public/hot` names
  `localhost`; set `LOCAL_APP_URL` to the URL the browser uses and verify from that URL, never only on the box. The
  board at `/kanban` answers only to IPs, `localhost`/`*.localhost`, `*.test` and the host of `LOCAL_APP_URL` (more via
  `kanban.ui.hosts`); another name gets 403 `Kanban answers only to this machine's own names`.
- **The container dials 127.0.0.1 or `redis:<host port>`** → compose substitutes `${VAR}` from the repo's `.env`,
  which is the host-side env; the local compose hardcodes every wiring value and driver, and prod always runs with
  `--env-file .env.prod`.
- **`php artisan test` in the container empties the dev database** → compose's process env sits in `$_SERVER`, which
  Laravel reads before PHPUnit's `<env>`; force them and mirror them with `tests/bootstrap.php`
  (`references/project-files.md`).
- **Redirects drop the port (to `http://localhost/…`)** → Debian's `fastcgi_params` passes `HTTP_HOST` as `$host`;
  `nginx-local.conf` passes `$http_host`.
- **Every request seems to come from 127.0.0.1** → Vite proxies all of them; `xfwd: true` plus
  `TRUSTED_PROXIES: 127.0.0.1` hand Laravel the client's address.
- **Anyone on the LAN opens `/horizon`** → Horizon admits every request in `local`, and the local stack listens on the
  LAN; the gate (laravel-project-setup) admits local requests only from the host itself, which needs the client address
  above. A publish without a host address also binds `[::]`, where Docker's proxy re-originates IPv6 clients as the
  bridge gateway, i.e. as the host: the web port is published on an explicit IPv4 address (`WEB_BIND`).
- **Anyone who can reach the web port reads and edits the board at `/kanban`** → it has no login, the local stack is
  LAN-visible by default, its writes are pushed by sync and card text reaches worker prompts. Set `KANBAN_UI_TOKEN=<secret>`
  in `.env` (open `/kanban?token=<secret>` once per browser; the CLI and agents are unaffected), or `WEB_BIND=127.0.0.1`,
  or `KANBAN_UI=false`. The token also closes the cross-origin read Vite's `cors: true` allows from a page on another
  local port.
- **An empty 500 with nothing in any log** → a fatal the handler cannot report, usually `memory_limit` (512M in both
  images). Reproduce it through the CLI front controller:
  `REQUEST_URI=/path REQUEST_METHOD=GET php -d variables_order=EGPCS -d log_errors=1 -d error_log=/tmp/e.log public/index.php`,
  then read `/tmp/e.log`.
- **Scheduled mail, webhooks or paid API calls fire several times** → every local and worktree stack runs
  `schedule:work`; outbound schedules stay off outside production or behind a flag.
- **The board in `/kanban` shows *Not synced* or *Not pushed*, or never shows others' changes** → the page syncs from the
  app container: the php-fpm worker `exec()`s `kanban sync --background` with the clone's deploy key
  (`.git/laravel-kanban/deploy_key`, named by `GIT_SSH_COMMAND` in the compose; ssh `origin` only; an admin registers its
  public half with write access). An https `origin` needs a credential helper of the container's own: without one the
  sync prints `fetch failed: … could not read Username`. Diagnose with
  `docker compose -f docker-compose.local.yml exec app vendor/bin/kanban sync` as the compose user, never `-u root`:
  - `Permission denied (publickey…)` → the key is not registered yet.
  - `sync: no remote configured` although the host has an origin → git refuses `/app` as dubious ownership (the process
    uid is not the checkout's owner: set `HOST_UID`/`HOST_GID` in `.env` and rebuild) or `git` is off the PATH.
  - `sync: up to date`, yet the page shows no notice and never updates → `exec()` disabled, `php` off the php-fpm worker's
    PATH (the CLI has its own), or an unwritable `.git/laravel-kanban`.
  - `N UI writes are saved but not committed yet` → git unusable for the worker: an unwritable `.git`, or `git` off its PATH.

  Run `doctor` and `attach` on the host only: in the container they see host paths. A write from the page names no one
  unless `KANBAN_USER` is in `.env`. The laravel-kanban gotchas own the rest.

## Verify

Image builds take minutes: run them in the background.

1. `docker compose -f docker-compose.local.yml up -d --build --wait`, then
   `docker compose -f docker-compose.local.yml exec app healthcheck.sh` prints no ✗.
2. From another machine's URL (`LOCAL_APP_URL`): the page and every asset answer 200, `public/hot` names that origin, and, once
   laravel-kanban is installed, `/kanban` answers 200 too (add `?token=<secret>` when `KANBAN_UI_TOKEN` is set).
3. Restart the app container twice; it is healthy both times (seed crash loops and stale caches show on the second
   boot).
4. `docker compose -f docker-compose.local.yml exec app php artisan test` leaves the dev database's rows untouched.
5. Prod shape (next to a running local stack, `WEB_PORT` in `.env.prod` differs from the local one):
   `docker compose --env-file .env.prod up -d --build --wait`; `octane:frankenphp` runs as www-data,
   `curl -fsS http://127.0.0.1:${WEB_PORT}/up` answers, the port is bound to 127.0.0.1 only, a `/build/*` asset carries
   `immutable`, and `php artisan about --only=cache` in the container shows everything cached.
