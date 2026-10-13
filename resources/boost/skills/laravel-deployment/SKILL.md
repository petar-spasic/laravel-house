---
name: laravel-deployment
description: >-
  House hosting for a Laravel app in Docker in three images: local (Caddy + php-fpm + Xdebug + the Vite or SvelteKit
  dev server, bind-mounted source), agent (the same with Octane in place of php-fpm, no Xdebug: card and merge-queue
  stacks) and production (FrankenPHP + Octane, non-root), each running the web server, the scheduler and Horizon under
  supervisor next to Postgres and Redis. Ships the Dockerfiles, entrypoints, compose files, Caddyfiles and configs as
  templates rendered per module, plus the boot order, the queue timeout chain, worktree stacks from one compose,
  forced phpunit env and the traps behind a container that boots but serves nothing. Module references: Reverb, spa,
  tenancy database roles. Use when dockerizing a Laravel project, writing or debugging its Docker, compose or Caddy
  files, or filling the Hosting section of its CLAUDE.md. Triggers — Laravel Docker, docker-compose Laravel, Caddy
  Laravel, FrankenPHP, Octane deploy, Reverb deploy, adapter-node deploy, supervisord Laravel.
---

# Laravel deployment

One repo ships three images of one stack. Caddy is the web server in each. Each image runs the web server, the
scheduler and Horizon under supervisor in one app container, next to Postgres 16 and Redis 7 sidecars. The local stack
is the dev environment: no `artisan serve`, no `composer run dev`, no sqlite, and no driver that differs between the
tiers.

## Images

| Image | Files | Front | Behind it |
|---|---|---|---|
| local | `Dockerfile.local`, `docker-compose.local.yml` | Caddy on container port 8080 | php-fpm with Xdebug on 127.0.0.1:9000; the `vite` dev server on 127.0.0.1:5173 (Vite, or SvelteKit with spa) |
| agent | `Dockerfile.agent`, `docker-compose.local.yml` | FrankenPHP's Caddy on container port 8080 | Octane workers (`--watch`), no Xdebug; the same `vite` dev server |
| prod | `Dockerfile`, `docker-compose.yml`, `.env.prod` | FrankenPHP's Caddy on container port 8080 | Octane workers as www-data; with spa, Node SSR (`ssr`) on 127.0.0.1:3000 |

Local runs php-fpm: each request is a fresh PHP process, so Xdebug breakpoints always fire (under Octane only a worker's
first request breaks). Local bind-mounts the source. The agent image is local's environment on prod's runtime: the
stacks of card clones and the merge queue build it (Many stacks, below), so the card agents test on warm workers,
where request state leaks as in prod. Main's own stack stays local. Prod keeps workers warm; TLS ends at the host's
reverse proxy.

## Templates

`${CLAUDE_SKILL_DIR}/templates/core` mirrors the project root, `templates/modules/<module>` holds a module's own
files, and `templates/snippets` the merges into files the skeleton already has. A module's changes to shared files
are `# if:<module>` … `# endif` blocks (`<!-- if:… -->` in Markdown) that laravel-project-setup's `install.php`
resolves. A row marked with a module belongs to that module only.

| Template | Carries |
|---|---|
| `Dockerfile` | composer stage (ignores only `ext-pcntl`) → node stage (whole tree + `vendor/`, for Tailwind's `@source`) → runtime: extensions, `composer check-platform-reqs`, `USER www-data` |
| `Dockerfile.local` | php-fpm, Caddy (binary from `caddy:2`), NodeSource 24, git, openssh-client, unzip, Xdebug; www-data re-homed to `HOST_UID`/`HOST_GID` |
| `Dockerfile.agent` | prod's FrankenPHP runtime with `Dockerfile.local`'s tools, users and caches, no Xdebug; opcache revalidates each file |
| `docker-compose.local.yml` | the many-stacks shape, hardcoded wiring and drivers, `LOCAL_APP_URL`, host cache binds; the image from `KANBAN_DOCKERFILE` |
| `docker-compose.yml` | loopback publish, `restart`, log rotation, Redis `noeviction` + AOF, `stop_grace_period` |
| `docker/docker-entrypoint.sh`, `docker/docker-entrypoint-local.sh` | the boot order below; the local one also boots the agent image (`octane`) |
| `docker/healthcheck.sh`, `docker/supervisord.conf` | `/up` plus every process the entrypoint configured; supervisor non-root, socket and pid in `/tmp`, the mandatory `[include]` |
| `docker/Caddyfile` | prod: files off disk (`/build/*` immutable), the rest to the worker |
| `docker/Caddyfile.local` | the local and agent sites: Vite's paths and HMR socket to 127.0.0.1:5173, the rest to `(php)` |
| `docker/Caddyfile.fpm`, `docker/Caddyfile.agent` | each image's global options and `(php)`, then `import` of `Caddyfile.local`: php-fpm, or Octane's worker |
| `docker/Caddyfile.frontend`, `docker/e2e.sh` (spa) | SvelteKit's build: files off disk, the rest to Node; browser tests on `{{app}}_test` |
| `docker/php-fpm-e2e.conf` (spa) | the e2e sites' php-fpm on 127.0.0.1:9001, started with Xdebug off |
| `docker/xdebug.ini`, `docker/postgres/init-test-db.sql` | Xdebug on trigger; `{{app}}_test` on a fresh volume |
| `docker/postgres/roles.sql` (tenancy) | the app's database role `{{app}}_app` and its grants |
| `tests/bootstrap.php` | forced phpunit `<env>` mirrored into `$_SERVER`; one test run at a time (a lock the next run waits on) |
| `.dockerignore`, `.env.prod.example` | the build context (never `public/hot`, `vendor/` or the FrankenPHP binary); the production env |
| `snippets/` | `vite.config.js`, `phpunit.xml`, `.env`, the tenancy project files and the Hosting section (`references/project-files.md`) |

Placeholders, each a `--set`: `app` the slug · `app_name` its `APP_NAME` · `php_version` the one PHP minor of host,
lock and images · `web_port`, `db_port`, `redis_port` main's host ports · `ws_port` Reverb's host port (reverb without
spa) · `domain` the prod host name. laravel-project-setup picks the PHP minor and the ports.

## Procedure

The modules are the project's, as laravel-project-setup chose them: `htmx`, `islands`, `spa`, `reverb`, `tenancy`.

1. **Render.** One script renders the templates: it resolves the module blocks, fills the placeholders, writes the
   scripts executable and never overwrites a file. Run it with `--dry-run` first, then without:

   ```shell
   php "${CLAUDE_SKILL_DIR}/../laravel-project-setup/scripts/install.php" . --templates="${CLAUDE_SKILL_DIR}/templates" \
     --modules=htmx,reverb --set app={{app}} --set app_name={{app_name}} --set php_version={{php_version}} \
     --set web_port={{web_port}} --set db_port={{db_port}} --set redis_port={{redis_port}} --set ws_port={{ws_port}} \
     --set domain={{domain}} --dry-run
   ```

   - A `placeholders left in` line means a missing `--set`: rerun with it.
   - The files it lists as skipped already exist. Leave them for step 2.
2. **Merge.** Run the same command with `--render-to="$(mktemp -d)"` in place of `--dry-run`. It writes nothing into the
   repo, and its first output line names `<dir>`. Diff each skipped file against `<dir>`'s copy and merge, keeping what
   is project-specific. Merge `Dockerfile.agent`, `docker/Caddyfile.agent`, `docker/Caddyfile.fpm`,
   `docker/Caddyfile.local` and `docker/docker-entrypoint-local.sh` together. Card stacks switch to the agent image only
   once `vendor/bin/kanban doctor` reports that set complete. Show the owner. With the owner's OK, remove any `docker/`
   file that no template ships and nothing references: no Dockerfile, compose file, entrypoint or Caddyfile `import`.
3. **Snippets.** Merge `<dir>/snippets/` as `references/project-files.md` lists. Never copy a raw template or snippet:
   their blocks and placeholders are unresolved.
4. **Hosting.** Fill `{{hosting}}` in the root `CLAUDE.md` from `<dir>/snippets/hosting-section.md`, adjusted to what
   was built. Then delete `<dir>`.
5. Run Verify (below).

## Modules

Each module's blocks carry its changes. Its reference holds the why, the traps and its Verify steps.

| Module | Reference | What its blocks change |
|---|---|---|
| `htmx`, `islands` | none | the root Vite as the `vite` program; the `vite.config.js` snippet |
| `reverb` | `references/reverb.md` | a `reverb` program on 8081, its env and its browser address |
| `spa` | `references/spa.md` | SvelteKit on Node behind Caddy: the path split, the `ssr` program, browser tests |
| `tenancy` | `references/tenancy.md` | the app connects as `{{app}}_app`; migrations run as the owner |
| API-only | none | no dev server. Commit the root `package-lock.json` (`npm install` on the host): every image runs `npm ci`, and the local boot stops without it |

## Boot order

Both entrypoints run these steps in order, so healthy means migrated and seeded. Their comments give the reasons.
"Local" is `docker-entrypoint-local.sh`, which boots the local image and, with the argument `octane`, the agent image.

1. **Prepare.** Local: `.env` from `.env.example` when missing; `composer install` and `npm ci` (in `.` and `frontend/`)
   only when a lockfile's sha256 changed; `key:generate` when `APP_KEY` is empty. Prod: refuse a bad `OCTANE_WORKERS`
   or an empty `TRUSTED_PROXIES`, then create the `storage/` directories the volume may lack.
2. **Rebuild the package manifest:** delete `bootstrap/cache/{packages,services}.php`, run `package:discover`.
3. **Clear caches** (local): `config:clear`, `route:clear`, `event:clear`, `view:clear`. Never cache locally.
4. **Wait for the database:** 60 × 2 s, then "database unreachable" and exit 1. It only connects.
5. **`migrate --force`**, once and loudly. With tenancy it runs as the owner (`references/tenancy.md`).
6. **Seed.** Reference data (`ReferenceDataSeeder`) on every boot. `DATABASE_SEED` decides the accounts. Local: `auto`
   (the full seed when `users` is empty), `true` (the full seed), `false`. Prod: `true` (`ProductionSeeder`: the
   operator and the admins), `false`. Anything else exits 1. The seeders are idempotent (laravel-project-setup), so a
   second boot never crash-loops.
7. **Build caches** (prod): `config:cache`, `route:cache`, `event:cache`, `view:cache` (with spa, only when
   `resources/views` exists).
8. **Start supervisor.** The entrypoint writes one supervisor `[program]` block per process (name, command, optional
   `stopwaitsecs` and directory under `/app`), then runs `exec supervisord -n`. Local: php-fpm (and with spa
   `php-fpm-e2e`, Xdebug off, for the e2e sites), caddy; the agent image: web, Octane with `--watch`, two workers and
   `docker/Caddyfile.agent`, after refusing a project without Octane or a watch list. Then `rm -f public/hot` and at
   most one dev server, `vite` on 127.0.0.1:5173 (htmx: the root Vite; spa: `frontend/`'s once it exists; API-only:
   none). Prod: web, plus `ssr` with spa. All: scheduler, and horizon with `stopwaitsecs` 70. Each groups every
   program as `app` (`app:web`, `app:caddy`, …), so a stop takes the longest `stopwaitsecs`, not their sum.

## Processes and limits

- Horizon owns the worker pool (`config/horizon.php`); never `queue:work`. Every image has `pcntl` (Horizon needs it).
- Queue chain: Redis `retry_after` (`REDIS_QUEUE_RETRY_AFTER`, 90) > Horizon's `timeout` (60) > the longest job's
  `$timeout`. Horizon's `stopwaitsecs` (70) > that timeout. Compose `stop_grace_period` (80) > the largest
  `stopwaitsecs` in the `app` group. A longer job raises all of them, in that order; otherwise a running job is
  handed to a second worker mid-run.
- Node chain (spa): adapter-node's `SHUTDOWN_TIMEOUT` (20) < `ssr`'s `stopwaitsecs` (25) < `stop_grace_period` (80).
  adapter-node drains open requests and never calls `process.exit`, so a shorter `stopwaitsecs` kills it mid-drain.
- `OCTANE_WORKERS` is a number, never `auto`: workers × `PHP_MEMORY_LIMIT` (512M) plus Horizon must fit the RAM.
- One Redis holds queue, cache and sessions: prod's `noeviction` and AOF make memory pressure fail writes, not drop jobs.

## Many stacks from one local compose

- `name: "${COMPOSE_PROJECT_NAME:?…}"`. Main's `.env` names `{{app}}-local`. A checkout without its own `.env` fails
  instead of taking over main's containers.
- No `container_name`, no volume or network `name:`, no `image:` on a built service.
- Every published port is a `${VAR:-default}`. The web port binds `${WEB_BIND:-0.0.0.0}`, IPv4 on purpose (the
  `/horizon` trap). The sidecars bind `${SIDECAR_BIND:-127.0.0.1}`.
- Tool caches are host bind mounts, because `down -v` deletes named volumes.
- The app also mounts `./` at `${KANBAN_WORKTREE_PATH:-/app}`: a card stack has its worktree at the host path too,
  where the card agents' shells run. `GIT_SSH_COMMAND` is `${KANBAN_GIT_SSH_COMMAND-…}`, which a worktree's `.env` sets
  empty, so only main's stack pushes the board.
- `build.dockerfile` is `${KANBAN_DOCKERFILE:-Dockerfile.local}`. The package writes `KANBAN_DOCKERFILE=Dockerfile.agent`
  into a card's and the merge queue's `.env` when their clone and main hold the agent image's files as the templates
  ship them (`doctor` names what is missing or old); main's `.env` never sets it. An edit to `Dockerfile.agent` or
  `docker/` rebuilds those stacks.
- The dev server ignores `./.claude/worktrees`, `./docs` and `./vendor`. `phpunit.xml` never sets `DB_HOST`/`DB_PORT`.
- The generated worktree `.env`, the port pool and Docker address pools: the house README, "Worktree Stacks".

## Config and env

Where a value lives and the drift to fix on sight: `references/project-files.md`, Config and env.

## Traps

**Container, boot and tests**
- **supervisord aborts ("… is badly formatted"), or a command runs mangled** → a `%` in a `program` command is a format
  specifier. `%Y` and `%d` abort; `%s` splices the whole expansion dict, environment included. Write `%%`.
- **Container runs, zero services** → `supervisord.conf` lacks `[include] files = /etc/supervisor/conf.d/*.conf`.
- **Providers missing after an image update** → a stale `bootstrap/cache` manifest; `optimize:clear` cannot help, since
  artisan itself fails to boot. Persist `storage/` only, never `bootstrap/cache`; the entrypoint rebuilds the manifest.
- **Horizon refuses to start, hung jobs never time out, stops are not graceful** → `pcntl` is missing from the image.
- **A `COPY` line with a trailing `# comment` fails or copies junk** → Dockerfile comments go on their own line.
- **The container dials 127.0.0.1 or `redis:<host port>`** → compose substitutes `${VAR}` from the repo's `.env`, the
  host-side env. The local compose hardcodes every wiring value and driver; prod runs with `--env-file .env.prod`.
- **The boot stops at `npm ci in … failed` with `ERESOLVE`, although `npm install` worked on the host** → `npm install`
  only warns about a peer conflict; `npm ci` refuses it. Fix versions until `npm ci` passes, then commit the lockfile.
- **An empty 500 with nothing in any log** → a fatal the handler cannot report, usually `memory_limit` (512M in both
  images). Reproduce it through the CLI front controller, then read `/tmp/e.log`:
  `REQUEST_URI=/path REQUEST_METHOD=GET php -d variables_order=EGPCS -d log_errors=1 -d error_log=/tmp/e.log public/index.php`.
- **`php artisan tinker` in the prod container exits 1 (`/config/psysh is not allowed`)** → FrankenPHP's image sets
  `XDG_CONFIG_HOME=/config`. Run `docker compose --env-file .env.prod exec -e XDG_CONFIG_HOME=/tmp app php artisan tinker`.
- **`php artisan test` in the container empties the dev database** → compose's env sits in `$_SERVER`, which Laravel
  reads before PHPUnit's `<env>`. `tests/bootstrap.php` mirrors the forced values (`references/project-files.md`).
- **`php artisan test --parallel` fails most tests with `SQLSTATE[53200]` "out of shared memory", while a serial run
  passes** → each worker's database reset locks every table, index and sequence in one transaction, and all workers
  share Postgres's lock table. The local compose starts Postgres with `max_locks_per_transaction=256`; raise it as the
  schema grows. It is a server setting: `up -d` recreates the container and keeps the volume.
- **Scheduled mail, webhooks or paid API calls fire several times** → every local and worktree stack runs
  `schedule:work`. Outbound schedules stay off outside production, or behind a flag.

**Serving**
- **`/.htaccess` or `/.env` is served** → a file matcher that skips dotfiles is not enough: `php_server` (prod, agent)
  and `file_server` (local) serve any existing file. Each Caddyfile answers them 404 first (`@hidden`).
- **HMR never connects; edits need a manual reload** → the socket reached php-fpm. `server.ws.path: '/__vite_hmr'` in
  `vite.config.js` and the same path in Caddy's `@vite` matcher go together (Vite 8.1+).
- **An asset or font answers 403, or Laravel's 404 page** → Vite may not serve it, or Caddy does not route it to Vite.
  See the comment above `@vite` in `docker/Caddyfile.local`.
- **Vite answers 403 "Blocked request", or module scripts fail CORS, under a `.test` or other name** →
  `server.allowedHosts` and `server.cors` must list it. IPs and localhost always pass. Never `true`.
- **Octane's status, reload or stop fail in prod or a card stack** → that Caddyfile has `admin off`. Octane drives
  FrankenPHP through Caddy's admin API, so `admin off` belongs in `docker/Caddyfile.fpm` only.

**The agent image** (card and merge-queue stacks)
- **A card stack serves the old code after an edit** → `--watch` restarts the workers only for what
  `config/octane.php`'s `watch` lists (`app`, `bootstrap`, `config`, `database`, `public`, `resources`, `routes`,
  `composer.lock`, `.env`; never `vendor/` or `node_modules/`). Anything else, a Caddyfile edit included, takes
  `supervisorctl restart app:web`.
- **Every PHP request to a card stack hangs after an edit** → the workers cannot boot: a parse error, or an exception
  while the app boots. The log says only "worker script has not reached frankenphp_handle_request()"; `php artisan
  about` in the container shows the error. FrankenPHP keeps retrying (`max_consecutive_failures -1` in
  `docker/Caddyfile.agent`), so the next watched edit that fixes it serves again, with no restart.
- **A flow works on main's stack and fails, or shows another request's data, in a card stack** → request state in a
  shared position, which only a warm worker keeps (laravel-project-setup's `app/CLAUDE.md`, Octane).

**From another machine**
- **The page loads from another machine but every asset fails with `ERR_CONNECTION_REFUSED`** → `public/hot` names
  `localhost`. Vite writes `APP_URL` into it at start; compose sets `APP_URL` from `LOCAL_APP_URL`. Set `LOCAL_APP_URL`
  to the URL the browser uses and apply it with `up -d` (a `restart` keeps the old env). Verify from that URL.
- **Anyone on the LAN opens `/horizon`** → Horizon admits every request in `local`, and the stack listens on the LAN.
  The gate (laravel-project-setup) admits only the host: loopback or the Docker gateway. Caddy hands PHP the TCP
  peer as `REMOTE_ADDR`, so keep that address honest:
  - Laravel trusts `X-Forwarded-For` only from `TRUSTED_PROXIES: 127.0.0.1`, Caddy only from `127.0.0.1/8`. Never
    `private_ranges` in `Caddyfile.fpm` or `Caddyfile.agent`: the LAN and the gateway are private ranges, so a LAN
    client could forge it.
  - Docker must keep the client's source address, as native Linux Docker does. The host's own requests to the LAN URL
    then get 403; use `localhost` there. Rootless Docker's builtin port driver, and possibly Docker Desktop, hand every
    client over as the gateway: there set `WEB_BIND=127.0.0.1`.
  - Publish on an explicit IPv4 `WEB_BIND`: a bare publish also binds `[::]`, where IPv6 clients arrive as the gateway.

**The board page**
- **Anyone who can reach the web port reads and edits the board at `/kanban`** → it has no login, the stack is
  LAN-visible by default, sync pushes its writes, and card text reaches worker prompts. Set `KANBAN_UI_TOKEN=<secret>`
  in `.env` and open `/kanban?token=<secret>` once per browser, or set `WEB_BIND=127.0.0.1` or `KANBAN_UI=false`.
- **The board in `/kanban` shows *Not synced* or *Not pushed*, or never shows others' changes** → the page syncs from the
  app container: an ssh `origin` needs the clone's deploy key registered with write access, an https one
  `KANBAN_GIT_TOKEN` in `.env`. Diagnose with `references/board-page.md`.

## Verify

Image builds take minutes: run them in the background. `dc` stands for `docker compose -f docker-compose.local.yml`.

1. **The probes.** `docker/verify.sh` prints nothing. It brings the local stack up and checks the healthcheck, the
   supervisor programs of the chosen modules, `/up`, the 404s of `/.env` and `/frankenphp-worker.php`, Laravel's JSON
   404 under `/api/v1` (API-only and spa), `/kanban` once installed, and two restarts.
2. **From another machine's URL** (`LOCAL_APP_URL`, applied with `up -d`); spa uses step 5 instead. A missing `/x.php`
   gets Laravel's 404 page. htmx and islands: the page and every asset answer 200, `public/hot` names that origin,
   and HMR connects at `/__vite_hmr`.
3. **Tests.** `dc exec app php artisan test` leaves the dev database's rows untouched; a second run started meanwhile
   waits for the first.
4. **Prod shape.** Next to the local stack, with another `WEB_PORT` and `TRUSTED_PROXIES` set in `.env.prod`:
   `docker/verify.sh prod` prints nothing. It checks Octane runs as www-data, `/up` on `127.0.0.1:${WEB_PORT}`, an
   `immutable` `/build/*` asset, the 404s of `/frankenphp-worker.php` and `/index.php`, the caches and the `app:*`
   programs. Behind the outer proxy, `request()->ip()` is the browser's address.
5. **Modules.** spa: `references/spa.md`, Verify. tenancy: `references/tenancy.md`, Verify.
6. **The agent image.** In a card's clone, whose `.env` names `KANBAN_DOCKERFILE=Dockerfile.agent`,
   `docker/verify.sh` prints nothing; it expects `app:web` in place of php-fpm and caddy. In that stack `php -m` lists
   no Xdebug, a route edit answers within seconds with `app:web`'s uptime unbroken, and `php artisan test` passes.
