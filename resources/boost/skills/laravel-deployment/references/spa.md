# SvelteKit hosting — the `spa` module only

With `spa`, Laravel and SvelteKit on Node (`adapter-node`) share one Caddy port and one origin, so the Sanctum session
cookie needs no CORS. This file is the hosting side; the frontend's rules are `frontend/CLAUDE.md`. Merge all of it
with the module: the spa template rows and the edits below. The `@reverb` blocks come only with the reverb module
(`references/reverb.md`); without it, `/app/*` is SvelteKit's. Placeholders as in SKILL.md.

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
  `@backend` in both Caddyfiles. This check prints nothing when all are covered (`_boost/browser-logs` is Boost's
  dev-only route and stays unrouted):

```shell
php artisan route:list --json | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) if (! preg_match("#^(api/|sanctum/|horizon(/|$)|up$|storage/|kanban(/|$)|_boost/)#", $r["uri"])) echo $r["uri"], PHP_EOL;'
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

## Local

### docker/Caddyfile.local

Keep the global block. Replace the header's first line with `# Local front server: Laravel's paths to php-fpm,
everything else to SvelteKit's dev server; two loopback sites for browser tests.` Replace everything after the global
block with this, and fill `{{app}}`. The base PHP branch becomes the `(laravel_root)` snippet all three sites import:

```caddyfile
# Laravel's public/ with the base PHP branch's guards. A PHP file other than the front controller never runs, nor a
# subdirectory's index.php through its directory URL; a missing /x.php still reaches Laravel, as in prod.
(laravel_root) {
	root * /app/public
	@hidden {
		path */.*
		not path /.well-known/*
	}
	respond @hidden 404
	@phpfile {
		file {
			try_files {path}
			split_path .php
		}
		path *.php *.php/*
		not path /index.php /index.php/*
	}
	respond @phpfile 404
	@phpdir {
		file {path}/index.php
		not path /
	}
	respond @phpdir 404
}

# Browser tests (docker/e2e.sh): the only list of the test env. Both e2e sites import it and e2e.sh reads its env
# lines (one `env NAME value` per line, no quotes). FastCGI params win over the container env in $_SERVER and
# getenv(); a cached config ignores them.
(e2e_php) {
	php_fastcgi 127.0.0.1:9000 {
		env APP_URL http://127.0.0.1:8090
		env DB_DATABASE {{app}}_test
		env REDIS_DB 2
		env REDIS_CACHE_DB 3
		env QUEUE_CONNECTION sync
		env MAIL_MAILER log
		env BROADCAST_CONNECTION null
		env SANCTUM_STATEFUL_DOMAINS 127.0.0.1:8090
		read_timeout 30m
	}
}

:8080 {
	route {
		# Stock caddy has no br encoder.
		encode zstd gzip
		@healthz path /healthz /healthz/*
		respond @healthz 404

		# Laravel's paths (references/spa.md); everything else is SvelteKit's.
		@backend path /api/* /sanctum/* /horizon /horizon/* /up /storage/* /kanban /kanban/*
		handle @backend {
			import laravel_root
			php_fastcgi 127.0.0.1:9000 {
				# An Xdebug session may hold a request for minutes.
				read_timeout 30m
			}
			# php_fastcgi runs PHP only; /storage files need this.
			file_server
		}

		# reverb module only: Echo's WebSocket on the page's own origin.
		@reverb path /app/*
		handle @reverb {
			reverse_proxy 127.0.0.1:8081
		}

		# vite dev, its HMR socket included.
		handle {
			reverse_proxy 127.0.0.1:5173 {
				header_up X-Real-IP {client_ip}
			}
		}
	}
}

# The browser's origin in browser tests: Laravel's paths on the test env, the rest from the production build of
# frontend/. Loopback only, never published.
:8090 {
	bind 127.0.0.1
	route {
		encode zstd gzip
		@healthz path /healthz /healthz/*
		respond @healthz 404

		@backend path /api/* /sanctum/* /up /storage/*
		handle @backend {
			import laravel_root
			import e2e_php
			file_server
		}

		import /app/docker/Caddyfile.frontend
	}
}

# SvelteKit's API_INTERNAL_URL in browser tests: a second origin, because SvelteKit answers a server-side fetch to its
# own origin itself.
:8091 {
	bind 127.0.0.1
	route {
		import laravel_root
		import e2e_php
		file_server
	}
}
```

Why the test env holds what it does:
- `REDIS_DB` and `REDIS_CACHE_DB` keep e2e sessions, cache, rate limits and locks apart from dev. The reset's
  `cache:clear` then flushes only DB 3; a `CACHE_PREFIX` alone would flush dev's cache DB.
- `QUEUE_CONNECTION=sync`: the dev Horizon never runs a test job against the dev database. `APP_URL` points mailed
  links at the e2e site. `SANCTUM_STATEFUL_DOMAINS` matches Chromium's `Origin` there.
- A test-only switch, such as a faked outside system, is one more `env` line in `(e2e_php)`.

### docker-compose.local.yml

In the header, after the URL lines, add this line:
`#   Browser tests: docker compose -f docker-compose.local.yml exec app docker/e2e.sh`. On the `app` service add
`shm_size: 1gb`, headroom for Chromium. Never `ipc: host`: it shares the host's IPC namespace on a LAN-visible stack.
Under `environment` add:

```yaml
      # spa: SvelteKit's server-side calls go to Caddy in this container; the host web port is never 8080
      # (references/spa.md).
      API_INTERNAL_URL: http://127.0.0.1:8080
      # $env/static/public: vite dev, npm run check and every build need it. The same expression as APP_URL.
      PUBLIC_APP_URL: ${LOCAL_APP_URL:-http://localhost:${WEB_PORT:-{{web_port}}}}
      # The page origins Sanctum treats as the browser; the entrypoint appends APP_URL's host.
      SANCTUM_STATEFUL_DOMAINS: localhost:${WEB_PORT:-{{web_port}}},127.0.0.1:${WEB_PORT:-{{web_port}}}
```

Keep `TRUSTED_PROXIES: 127.0.0.1`: SvelteKit's server-side calls arrive from loopback, carrying the browser's address.

### docker/docker-entrypoint-local.sh

After the `.env` line:

```bash
# spa: Sanctum's stateful origins are compose's localhost and 127.0.0.1 at WEB_PORT plus APP_URL's host (LOCAL_APP_URL).
export SANCTUM_STATEFUL_DOMAINS="$SANCTUM_STATEFUL_DOMAINS,$(echo "${APP_URL#*://}" | cut -d/ -f1)"
# SvelteKit answers a fetch to its own origin itself: API_INTERNAL_URL is never a page's origin.
case ",$SANCTUM_STATEFUL_DOMAINS," in *",${API_INTERNAL_URL#*://},"*) echo "the web port is 8080, which is API_INTERNAL_URL's: set another WEB_PORT"; exit 1 ;; esac
```

The `vite` program needs no edit. `php artisan` through `docker compose exec` sees only compose's part of the list;
CLI requests are never stateful.

### Dockerfile.local

Chromium is installed at build, as root: the container runs as `HOST_UID`, and Chromium's libraries need apt. Before
the `FROM php:…` line:

```dockerfile
# spa: the @playwright/test version frontend/package-lock.json pins, empty until frontend/ exists; the browser layer
# rebuilds only when it changes. composer.json always exists, so the lockfile may match nothing; the directory is a
# pattern too, because BuildKit fails on a missing parent directory.
FROM node:24-bookworm-slim AS playwright-version
COPY composer.json fronten[d]/package-lock.jso[n] /tmp/ctx/
RUN if [ -f /tmp/ctx/package-lock.json ]; then node -p "require('/tmp/ctx/package-lock.json').packages['node_modules/@playwright/test']?.version ?? ''"; fi > /tmp/playwright-version
```

After the php-fpm `zz-listen.conf` line, before `ENV COMPOSER_CACHE_DIR=… npm_config_cache=/cache/npm` (that cache is
a host mount at runtime):

```dockerfile
# Headless Chromium, its libraries and fonts. Outside the bind mount, so npm ci never touches it; read-only at runtime.
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
COPY --from=playwright-version /tmp/playwright-version /tmp/playwright-version
RUN v=$(cat /tmp/playwright-version) && mkdir -p /ms-playwright \
    && if [ -n "$v" ]; then npx -y "playwright@$v" install --with-deps --only-shell chromium; fi \
    && echo "$v" > /ms-playwright/.version && chmod -R a+rX /ms-playwright \
    && rm -rf /var/lib/apt/lists/* /root/.npm /tmp/playwright-version
```

Playwright launches Chromium with `--no-sandbox`, so no seccomp profile or capability is needed.

## Browser tests on `{{app}}_test`

Neither `.env.testing` nor `--env` can switch the database: Laravel's env repository is immutable, and `DB_*` are real
container env vars. So the e2e sites pass the test env as FastCGI params. SSR needs its own Node process too, because
the dev server's SSR calls the dev database. A run therefore uses the production build of `frontend/` behind the real
prod split (`Caddyfile.frontend`). The e2e sites coexist with the dev stack.

The only entry is `docker compose -f docker-compose.local.yml exec app docker/e2e.sh [playwright test args]`. It holds
the test-database lock, refuses a cached config or a database not ending in `_test`, checks the image's browser
against the lockfile, resets with `migrate:fresh --seeder=ReferenceDataSeeder --force` and `cache:clear`, builds with
`PUBLIC_APP_URL=http://127.0.0.1:8090`, and runs Playwright with `E2E_DATABASE_READY=1`. Its comments give each guard.

**The lock.** Pest and Playwright never use the test database at once. e2e.sh holds the lock alone; Pest takes it
shared, so parallel workers coexist. It lives in the checkout, so a run on the host and one in the container see each
other on a Linux host (Docker Desktop's file sharing may not carry host locks). Append to `tests/bootstrap.php`:

```php
// spa: {{app}}_test has one user at a time. docker/e2e.sh holds this lock alone; test processes (parallel workers too)
// share it. In the checkout, so a run on the host and one in the container see each other.
$lockFile = __DIR__.'/../storage/framework/testing/db.lock';
is_dir(dirname($lockFile)) || mkdir(dirname($lockFile), 0775, true);
$GLOBALS['testDatabaseLock'] = fopen($lockFile, 'c');
if (! flock($GLOBALS['testDatabaseLock'], LOCK_SH | LOCK_NB)) {
    fwrite(STDERR, "The test database is in use by an e2e run (docker/e2e.sh); run the tests when it ends.\n");
    exit(1);
}
```

The handle stays in `$GLOBALS`, so the lock is held until the process exits.

**The Playwright contract.** `frontend/playwright.config.ts` is the frontend's file; these values are fixed here:

- `testDir: './e2e'`, `workers: 1`, `fullyParallel: false`; one project, `chromium` with `devices['Desktop Chrome']`
  and no `channel` (the image holds the headless shell only); `use.baseURL: 'http://127.0.0.1:8090'`.
- `webServer`: `command: 'node build'` (e2e.sh builds), `url: 'http://127.0.0.1:3000/healthz'`,
  `reuseExistingServer: false`, and an explicit `env`, because Playwright merges it over the container's own:
  `HOST: '127.0.0.1'`, `PORT: '3000'`, `ORIGIN: 'http://127.0.0.1:8090'`, `ADDRESS_HEADER: 'X-Real-IP'`,
  `API_INTERNAL_URL: 'http://127.0.0.1:8091'`, `PUBLIC_APP_URL: 'http://127.0.0.1:8090'`, `BODY_SIZE_LIMIT: '8M'`,
  and with reverb `REVERB_APP_KEY: ''`.
- `globalSetup` throws unless `process.env.E2E_DATABASE_READY === '1'` ("run docker/e2e.sh"): a bare
  `npx playwright test` would skip the lock and the reset.

Browser tests exercise no queue timing and no realtime: the e2e site runs `QUEUE_CONNECTION=sync` and
`BROADCAST_CONNECTION=null`, and an empty `REVERB_APP_KEY` means no Echo. Test those with Pest or by hand.

## Prod

### Dockerfile

Stage 2 becomes:

```dockerfile
# ── Stage 2: frontend ────────────────────────────────────────────────────────
# Debian, never alpine: the runtime copies this stage's node binary and node_modules into a glibc image.
# No PHP here: the validation export is committed, never generated in the build.
FROM node:24-bookworm-slim AS frontend-builder
WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
# Large builds exhaust Node's default heap ("Ineffective mark-compacts near heap limit").
ENV NODE_OPTIONS=--max-old-space-size=8192
# $env/static/public is baked in: the image is built per public URL (compose build.args).
ARG PUBLIC_APP_URL
ENV PUBLIC_APP_URL=$PUBLIC_APP_URL
# adapter-node bundles devDependencies into build/; the runtime keeps only "dependencies" (usually none).
RUN [ -n "$PUBLIC_APP_URL" ] || { echo "PUBLIC_APP_URL build arg missing"; exit 1; }; \
    npm run build && npm ci --omit=dev && mkdir -p node_modules
```

In the runtime stage, right after `FROM dunglas/frankenphp:…` and `WORKDIR`:

```dockerfile
# Node for the ssr program; fails the build early if the binary does not run on this Debian.
COPY --from=frontend-builder /usr/local/bin/node /usr/local/bin/node
RUN node --version
```

In place of `COPY --from=node-builder /app/public/build /app/public/build`:

```dockerfile
COPY --from=frontend-builder /app/frontend/build /app/frontend/build
COPY --from=frontend-builder /app/frontend/node_modules /app/frontend/node_modules
```

`frontend/package.json` comes with `COPY . /app`; `node build` needs it for `"type": "module"`.

### docker-compose.yml

The header's required list adds `SANCTUM_STATEFUL_DOMAINS`. `stop_grace_period` stays 80s. The `build:` line becomes:

```yaml
    # PUBLIC_APP_URL is baked into the SvelteKit build: a new APP_URL means a rebuild.
    build: { context: ., dockerfile: Dockerfile, args: { PUBLIC_APP_URL: "${APP_URL:?APP_URL is required in .env.prod}" } }
```

### docker/docker-entrypoint.sh

After the `TRUSTED_PROXIES` check:

```bash
# spa: APP_URL is also the SvelteKit server's ORIGIN: adapter-node refuses an empty one and, without one, assumes https
# and 403s every form post.
case "${APP_URL:-}" in
    http://*/*|https://*/*) echo "APP_URL must be the site's bare origin, no path or trailing slash (https://host), got '${APP_URL}'"; exit 1 ;;
    http://?*|https://?*) ;;
    *) echo "APP_URL must be the site's bare origin (https://host), got '${APP_URL:-}'"; exit 1 ;;
esac
# Empty makes nothing stateful: every browser /api call would 401.
[ -n "${SANCTUM_STATEFUL_DOMAINS:-}" ] || { echo "SANCTUM_STATEFUL_DOMAINS is empty: set the public host"; exit 1; }
# SvelteKit's server-side calls come from 127.0.0.1, browsers through the reverse proxy: Laravel trusts both.
case ",${TRUSTED_PROXIES// /}," in *,127.0.0.1,*) ;; *) echo "TRUSTED_PROXIES must include 127.0.0.1 (SvelteKit's server-side calls)"; exit 1 ;; esac
[ "$(echo "${TRUSTED_PROXIES// /}" | tr ',' '\n' | grep -cvxE '127\.0\.0\.1|')" -gt 0 ] || { echo "TRUSTED_PROXIES also needs the reverse proxy's address as its requests arrive in the container"; exit 1; }
```

Next to `program web`:

```bash
# SvelteKit (adapter-node) on loopback; Caddy is its only client and sets X-Real-IP. ORIGIN fixes event.url, so
# PROTOCOL_HEADER and HOST_HEADER are unused. SHUTDOWN_TIMEOUT (20) < stopwaitsecs (25) < compose stop_grace_period
# (80); the app group stops it together with the others. BODY_SIZE_LIMIT = PHP's post_max_size: raise both together.
program ssr "env HOST=127.0.0.1 PORT=3000 ORIGIN=${APP_URL%/} ADDRESS_HEADER=X-Real-IP BODY_SIZE_LIMIT=8M SHUTDOWN_TIMEOUT=20 API_INTERNAL_URL=http://127.0.0.1:8080 node build" 25 frontend
```

`ssr` starts with `web`, after migrating and caching, and joins the `app` group. Its values are literals, not
`.env.prod` entries: no two environments differ in them. The healthcheck template already checks
`127.0.0.1:3000/healthz` once `ssr.conf` exists.

### docker/Caddyfile

The `route { … }` block becomes:

```caddyfile
	route {
		encode zstd br gzip

		{$CADDY_SERVER_EXTRA_DIRECTIVES}

		@healthz path /healthz /healthz/*
		respond @healthz 404

		@backend path /api/* /sanctum/* /horizon /horizon/* /up /storage/*
		handle @backend {
			root * "{$APP_PUBLIC_PATH}"
			# php_server's try_files {path} would serve a dotfile and run an uploaded .php under /storage, also by a
			# PATH_INFO URL (/storage/x.php/y).
			@hidden path */.* *.php *.php/*
			respond @hidden 404
			php_server {
				index frankenphp-worker.php
				try_files {path} frankenphp-worker.php
			}
		}

		# reverb module only: Echo's WebSocket on the page's own origin.
		@reverb path /app/*
		handle @reverb {
			reverse_proxy 127.0.0.1:8081
		}

		import /app/docker/Caddyfile.frontend
	}
```

Inside a `handle`, Caddy runs directives in its standard order, so `respond` runs before `php_server`. The global
block, `{$CADDY_EXTRA_CONFIG}`, the site address and the log block stay. `docker/Caddyfile.frontend` holds the
SvelteKit rules, each explained in its comments.

### .dockerignore

Append these. `**/node_modules` already covers `frontend/node_modules`, and `$env/static/*` would bake a developer's
`.env` into the bundle:

```
frontend/build
frontend/.svelte-kit
frontend/.env
frontend/.env.*
frontend/e2e
frontend/test-results
frontend/playwright-report
```

### .env.prod.example

These replace the `APP_URL` and `TRUSTED_PROXIES` lines:

```dotenv
# A bare origin, no path or trailing slash: also the SvelteKit server's ORIGIN and the build's PUBLIC_APP_URL.
APP_URL=https://{{domain}}
# Comma-separated: 127.0.0.1 (SvelteKit's server-side calls, which carry the browser's address) and the reverse
# proxy's address as its requests arrive in the container (the compose network's gateway). Add the gateway: the
# entrypoint refuses a value without both.
TRUSTED_PROXIES=127.0.0.1,
# The page origins Sanctum treats as the browser (host[:port], no scheme). Empty makes every browser /api call 401.
SANCTUM_STATEFUL_DOMAINS={{domain}}
```

Left out on purpose: `ORIGIN` (derived from `APP_URL`; an empty one crashes adapter-node), `PROTOCOL_HEADER` (ignored
once `ORIGIN` is set) and `SESSION_DOMAIN` (unset gives the host-only cookie one origin needs; `.{{domain}}` would share
the session with subdomains).

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
- **`php artisan test` says the test database is in use** → an e2e run holds the lock. Wait for it to end.

## Verify

Run step 1 before and after `frontend/` exists (`frontend/CLAUDE.md`, First frontend change), and steps 2 onward after
it exists. `dc` stands for `docker compose -f docker-compose.local.yml`.

1. **Healthy, before and after `frontend/` exists.** `dc up -d --build --wait`, and the healthcheck prints no ✗.
   Before: no `vite` program; `/up` answers 200 and `/api/v1/x` answers Laravel's JSON 404. After:
   `cat /ms-playwright/.version` in the container equals the lockfile's `@playwright/test`. Restart twice; healthy both
   times.
2. **Config.** `caddy adapt --config /app/docker/Caddyfile.local` in the container succeeds, and the route-coverage
   command prints nothing.
3. **From another machine's URL.** `/` is SvelteKit's HTML, and a `.svelte` edit hot-updates. `/up` and an `/api/v1`
   route answer from Laravel, `/healthz` answers 404, and `/kanban` answers 200. An Xdebug breakpoint in an `/api`
   controller fires. Signing in through the SvelteKit page works via `localhost`, `127.0.0.1` and the LAN URL, and an
   SSR load of an `auth:sanctum` route then renders 200. 20 anonymous SSR renders leave Redis's session count unchanged.
4. **Browser tests.** Note the dev database's row counts, then `dc exec app docker/e2e.sh`. The session flow passes
   through 127.0.0.1:8090 with SSR calling :8091, and the counts are unchanged. During the run `php artisan test`
   refuses. During a Pest run, and after `php artisan config:cache`, e2e.sh refuses.
5. **Prod shape** (SKILL.md, Verify, step 5): healthy; `node build` and `octane:frankenphp` run as www-data, and
   `octane:status` works. A prerendered page carries `no-cache` and `frame-ancestors 'none'`. A real
   `/_app/immutable/*` chunk carries `immutable`, and `content-encoding: br` for `Accept-Encoding: br`; a missing one
   answers 404 with `no-store`. `/_app/env.js` answers 200. `/storage/x.php` and `/storage/x.php/y` answer 404.
   `APP_URL=`, an `APP_URL` with a trailing slash, an empty `SANCTUM_STATEFUL_DOMAINS`, or `TRUSTED_PROXIES=127.0.0.1`
   each stop the boot with their message. `docker compose stop app` ends `ssr` within 25 s.
