# SvelteKit hosting — the `spa` module only

With `spa`, Laravel and SvelteKit on Node (`adapter-node`) share one Caddy port and one origin, so the Sanctum session
cookie needs no CORS. This file is the hosting side's why, traps and checks; the frontend's rules are
`frontend/CLAUDE.md`. The templates' `spa` blocks carry every file change (SKILL.md, Procedure). The `@reverb` blocks
come only with the reverb module (`references/reverb.md`); without it, `/app/*` is SvelteKit's.

## Before `frontend/` exists

Setup writes only `frontend/CLAUDE.md`. Until the first frontend change commits `frontend/package.json` and a lockfile:

- the local stack boots healthy, with no `vite` program and no ping for one. Through Caddy, `/up` answers 200 and
  `/api/v1/x` answers Laravel's JSON 404; every other path answers 502;
- `Dockerfile.local` builds: its Playwright stage tolerates the missing lockfile;
- the prod image does not build: its frontend stage copies `frontend/`.

## The split

```
browser → Caddy :8080 ─┬─ @backend                     → Laravel (php-fpm locally, Octane in prod)
                       ├─ /app/* (reverb module)        → Reverb on 127.0.0.1:8081
                       ├─ a file of frontend/build      → off disk (prod and the e2e site)
                       └─ everything else               → SvelteKit (vite dev locally, node build in prod)
```

- Split by path only, never by method: SvelteKit form actions POST to Node.
- `@backend` is `/api/* /sanctum/* /horizon /horizon/* /up /storage/*`, plus `/kanban /kanban/*` locally
  (the house is a dev dependency, absent in prod). Never `/horizon*`: it also matches `/horizonX`.
- Fortify (`/api/auth`), Socialite (`/api/auth/{provider}/…`), channel auth (`/api/broadcasting/auth`) and the
  resources (`/api/v1`) all sit under `/api`. `/storage/*` is always there: the local disk's `serve => true` registers
  `/storage/{path}`, and the public disk links there.
- `/healthz` answers 404 at Caddy wherever a browser arrives. SvelteKit answers it on 127.0.0.1:3000, for the prod
  healthcheck only.
- Every route `php artisan route:list` shows must fall under these prefixes. A new one moves under `/api` or joins
  `@backend` in both Caddyfiles. This check prints nothing when all are covered:

```shell
php artisan route:list --json | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) if (! preg_match("#^(api/|sanctum/|horizon(/|$)|up$|storage/|kanban(/|$))#", $r["uri"])) echo $r["uri"], PHP_EOL;'
```

## Ports

| Port in the container | Listener | Tier |
|---|---|---|
| 8080 | Caddy (local), FrankenPHP (prod); the only published port | both |
| 9000 | php-fpm, loopback | local |
| 5173 | the `vite` program: `vite dev` in `frontend/`, loopback | local |
| 3000 | adapter-node, loopback: the `ssr` program in prod; locally only the e2e run's `node build` | both |
| 8090 | the e2e site, the browser's origin in browser tests; loopback, never published | local |
| 8091 | the e2e backend, SvelteKit's `API_INTERNAL_URL` in browser tests; loopback, never published | local |
| 8081 | Reverb (reverb module), reached through Caddy's `/app/*` | both |
| 2019 | FrankenPHP's admin API, which Octane's status, reload and stop use | prod |

**`API_INTERNAL_URL`'s origin never equals a page's origin.** SvelteKit answers a server-side fetch to its own origin
itself, and it has no `/api` routes. So `API_INTERNAL_URL` is `http://127.0.0.1:8080` in dev and prod and
`http://127.0.0.1:8091` in browser tests. The host web port, main's and every worktree's, is never 8080. The e2e Node
calls :8091, never :8090.

## What the blocks hold

- `docker/Caddyfile.local`: the split on :8080, plus two loopback sites for browser tests, :8090 (the browser's
  origin) and :8091 (the e2e backend). All three import `(laravel_root)`. The e2e sites import `(e2e_php)`, the one
  list of the test env; its comment says why each value is there.
- `docker-compose.local.yml`: `API_INTERNAL_URL`, `PUBLIC_APP_URL` and `SANCTUM_STATEFUL_DOMAINS`, and
  `shm_size: 1gb` for Chromium. `TRUSTED_PROXIES: 127.0.0.1` stays, because SvelteKit's server-side calls arrive from
  loopback carrying the browser's address.
- `docker/docker-entrypoint-local.sh`: refuses a web port of 8080. `config/sanctum.php` (laravel-project-setup's
  `config-sanctum.php` snippet) adds `APP_URL`'s host:port to the origins `SANCTUM_STATEFUL_DOMAINS` lists, in every
  process, `docker compose exec` included.
- `Dockerfile.local`: the headless Chromium for the exact `@playwright/test` pin, installed at build as root.
- Prod: `Dockerfile`'s frontend stage and Node binary, the `ssr` program, the boot checks and a `view:cache` that
  skips a missing `resources/views` (git keeps no empty directory) in `docker/docker-entrypoint.sh`, the
  `docker/Caddyfile` split, the build arg in `docker-compose.yml`, and `.dockerignore` and `.env.prod.example` lines.

## Browser tests on `{{app}}_test`

Neither `.env.testing` nor `--env` can switch the database: Laravel's env repository is immutable, and `DB_*` are real
container env vars. So the e2e sites pass the test env as FastCGI params. SSR needs its own Node process too, because
the dev server's SSR calls the dev database. A run therefore uses the production build of `frontend/` behind the real
prod split (`Caddyfile.frontend`). The e2e sites coexist with the dev stack.

The only entry is `docker compose -f docker-compose.local.yml exec app docker/e2e.sh [playwright test args]`. It holds
the test-database lock, refuses a cached config or a database not ending in `_test`, checks the image's browser
against the lockfile, resets with `migrate:fresh --seeder=ReferenceDataSeeder --force` and `cache:clear`, runs the
project's `docker/e2e-reset.sh`, builds with `PUBLIC_APP_URL=http://localhost:8090`, and runs Playwright with
`E2E_DATABASE_READY=1`. Its comments give each guard.

**The project's reset.** `docker/e2e-reset.sh`, when the project has one, runs after the database reset on the test
env, and must be executable. It holds what e2e.sh cannot know, so a re-render never loses it:

- the browser fixtures: a spec reaches no factory, so the data the specs sign in with and read comes from a seeder,
  `php artisan db:seed --class=<its seeder> --force`. The seeder throws unless `config('app.e2e')`, so it never runs on
  the dev or prod database;
- another stateful sidecar, such as a search index: emptied here, on the test env's names.

**The origin is `localhost:8090`**, never `127.0.0.1:8090`: WebAuthn refuses an IP as its relying party. Only what the
browser or Laravel sees uses it; server-to-server addresses (`API_INTERNAL_URL`, the webServer health URL, e2e.sh's
probes) stay `127.0.0.1`, because Node may resolve `localhost` to `::1` first.

**The lock.** One test run uses the test database at a time: e2e.sh and every top-level Pest run take
`storage/framework/testing/db.lock` exclusively (`tests/bootstrap.php`; parallel workers run under their parent's), and
a second run waits. It lives in the checkout, so a run on the host and one in the container see each other on a Linux
host (Docker Desktop's file sharing may not carry host locks).

**One switch.** The e2e site sets `APP_E2E`, and the app reads it as `config('app.e2e')`: every named rate limiter
answers `Limit::none()` under it (`app/Providers/CLAUDE.md`), so back-to-back sign-ins never wait out a 429, and a
test-only behaviour, such as a faked outside system, reads it too. `(e2e_php)` never gains a line per feature. The prod
entrypoint refuses `APP_E2E`.

**The CSRF token path.** Laravel 13 passes a write carrying `Sec-Fetch-Site: same-origin` without checking its token,
and browsers send Fetch Metadata only to https, `localhost` and loopback. The `:8090` site strips the header, so every
browser write in the run takes the token path a LAN http origin takes.

**The Playwright contract.** `frontend/playwright.config.ts` is the frontend's file; these values are fixed here:

- `testDir: './e2e'`, `workers: 1`, `fullyParallel: false`; one project, `chromium` with `devices['Desktop Chrome']`
  and no `channel` (the image holds the headless shell only); `use.baseURL: 'http://localhost:8090'`.
- `webServer`: `command: 'node build'` (e2e.sh builds), `url: 'http://127.0.0.1:3000/healthz'`,
  `reuseExistingServer: false`, and an explicit `env`, because Playwright merges it over the container's own:
  `HOST: '127.0.0.1'`, `PORT: '3000'`, `ORIGIN: 'http://localhost:8090'`, `ADDRESS_HEADER: 'X-Real-IP'`,
  `API_INTERNAL_URL: 'http://127.0.0.1:8091'`, `PUBLIC_APP_URL: 'http://localhost:8090'`, `BODY_SIZE_LIMIT: '8M'`,
  and with reverb `REVERB_APP_KEY: ''`.
- `globalSetup` throws unless `process.env.E2E_DATABASE_READY === '1'` ("run docker/e2e.sh"): a bare
  `npx playwright test` would skip the lock and the reset.

Browser tests exercise no queue timing and no realtime: the e2e site runs `QUEUE_CONNECTION=sync` and
`BROADCAST_CONNECTION=null`, and an empty `REVERB_APP_KEY` means no Echo. Test those with Pest or by hand.

## Client address and host

- **Caddy → Node.** Every site sets `X-Real-IP {client_ip}` under `trusted_proxies_strict`, and adapter-node reads it
  (`ADDRESS_HEADER=X-Real-IP`). Never `XFF_DEPTH`: behind a trusted outer proxy Caddy appends the peer, and depth 1
  would yield the proxy. With `ADDRESS_HEADER` set, `getClientAddress()` throws when the header is missing, which is
  why `/healthz` answers before anything reads it.
- **Node → Laravel.** `handleFetch` sends `/api/v1/*` to `API_INTERNAL_URL` with the forwarded headers
  (`frontend/CLAUDE.md`, Server: `handleFetch`). Laravel trusts `127.0.0.1`, so the call carries the browser's address,
  host and scheme. `API_INTERNAL_URL`'s host:port is never in `SANCTUM_STATEFUL_DOMAINS`, so an anonymous SSR call
  starts no session.
- **Prod trust.** `trusted_proxies static private_ranges` fits one outer proxy on the host. A client inside a private
  range that comes through that proxy counts as a proxy too, and its `{client_ip}` falls back to an entry it controls.
  Then narrow `trusted_proxies` and `TRUSTED_PROXIES` to the proxy's address.

## Traps

- **Every form action answers 403** → `ORIGIN` is missing or wrong, so adapter-node assumes https and its CSRF check
  fails. Prod derives `ORIGIN` from `APP_URL`; e2e sets it in the webServer env.
- **The prod container stops at boot with the `APP_URL` message** → `APP_URL` is not a bare http(s) origin. It is also
  `ORIGIN`, and an empty `ORIGIN` crashes adapter-node.
- **Every SSR `/api` call answers 404 from SvelteKit** → `API_INTERNAL_URL`'s origin equals the page's: a host web port
  of 8080 opened as `127.0.0.1:8080`, or e2e pointed at :8090. The local entrypoint refuses a web port of 8080.
- **Silent 401 on `/api` calls** → the page's `host:port` is missing from `SANCTUM_STATEFUL_DOMAINS` (the port is part
  of the match), or SSR does not send `Origin` when the `XSRF-TOKEN` cookie is present.
- **Every SSR call is throttled as one client, or Laravel logs 127.0.0.1** → `handleFetch` does not forward
  `X-Forwarded-For` from `X-Real-IP`, or `TRUSTED_PROXIES` lacks `127.0.0.1`.
- **Every browser call shares one throttle bucket, or URLs come out http:// in prod** → `TRUSTED_PROXIES` lacks the
  outer proxy's address as seen in the container.
- **A prerendered `/about` 404s** → a file matcher only tests existence: `rewrite * {file_match.relative}` serves it.
- **A missing chunk is cached for a year** → the immutable header sits outside the client-assets handle.
- **`$env/dynamic/public` breaks on prerendered pages** → Caddy answered `/_app/env.js`. Only `/_app/immutable/*` may
  404 there.
- **An SSR `event.fetch` of a static file or prerendered page hangs or fails in prod** → SvelteKit fetches those from
  the public origin, a hairpin through the outer proxy. Import the data or call the API instead.
- **413 on an upload** → the body exceeds `BODY_SIZE_LIMIT` (8M). Raise it together with PHP's `post_max_size` and
  `upload_max_filesize`.
- **e2e.sh refuses with "config is cached"** → `config:cache` or `optimize` ran locally. Run
  `php artisan config:clear`. Never cache locally: the e2e sites' env would be ignored.
- **`Executable doesn't exist` in a Playwright run** → `@playwright/test` changed without an image rebuild; e2e.sh
  refuses first. Run `docker compose -f docker-compose.local.yml up -d --build`.
- **`php artisan test` or e2e.sh prints "waiting for the test database"** → another test or e2e run holds the lock;
  it goes on when that run ends.
- **A write works on localhost and in browser tests but answers 419 from the LAN URL** → the token handling is broken
  (`frontend/CLAUDE.md`, Browser), and only a LAN http origin runs it on Laravel 13. The e2e site strips
  `Sec-Fetch-Site`, so the session flow proves it.
- **A permanent 419 on `localhost` after a worktree stack was opened there** → every stack on a host shares the
  `XSRF-TOKEN` cookie, because browsers keep cookies per host, not per port. The local compose gives each stack its own
  session cookie (`SESSION_COOKIE`), and the client refreshes the token and retries a 419 once.

## Verify

Run step 1 before and after `frontend/` exists (`frontend/CLAUDE.md`, First frontend change), and steps 2 onward after
it exists. `dc` stands for `docker compose -f docker-compose.local.yml`.

1. **Healthy, before and after `frontend/` exists.** `docker/verify.sh` prints nothing; it expects the `vite` program
   only once `frontend/package.json` exists. After: `cat /ms-playwright/.version` in the container equals the
   lockfile's `@playwright/test`.
2. **Config.** `caddy adapt --config /app/docker/Caddyfile.local` in the container succeeds, and the route-coverage
   command prints nothing.
3. **From another machine's URL.** `/` is SvelteKit's HTML, and a `.svelte` edit hot-updates. `/up` and an `/api/v1`
   route answer from Laravel, `/healthz` answers 404, and `/kanban` answers 200. An Xdebug breakpoint in an `/api`
   controller fires. Signing in through the SvelteKit page works via `localhost`, `127.0.0.1` and the LAN URL, and an
   SSR load of an `auth:sanctum` route then renders 200. 20 anonymous SSR renders leave Redis's session count unchanged.
4. **Browser tests.** Note the dev database's row counts, then `dc exec app docker/e2e.sh`. The session flow passes
   through `localhost:8090` with SSR calling :8091, and the counts are unchanged. During the run `php artisan test`
   waits, and so does e2e.sh during a Pest run. After `php artisan config:cache`, e2e.sh refuses.
5. **Prod shape** (SKILL.md, Verify, step 4): `docker/verify.sh prod` prints nothing; `node build` runs as www-data,
   and `octane:status` works. A prerendered page carries `no-cache` and `frame-ancestors 'none'`. A real
   `/_app/immutable/*` chunk carries `immutable`, and `content-encoding: br` for `Accept-Encoding: br`; a missing one
   answers 404 with `no-store`. `/_app/env.js` answers 200. `/storage/x.php` and `/storage/x.php/y` answer 404.
   `APP_URL=`, an `APP_URL` with a trailing slash, an empty `SANCTUM_STATEFUL_DOMAINS`, `TRUSTED_PROXIES=127.0.0.1` or
   `APP_E2E=true` each stop the boot with their message. The boot passes with no `resources/views`.
   `docker compose stop app` ends `ssr` within 25 s.
