# Project files the stack needs

Merge these into the skeleton's files. `{{app}}` and `{{web_port}}` as in SKILL.md.

## vite.config.js — the dev server behind Caddy (htmx, islands)

Vite runs in the local container on 127.0.0.1:5173. Caddy sends it Vite's dev paths and the HMR socket, and sends
everything else to php-fpm (`docker/Caddyfile.local`). Merge the block into the skeleton's `vite.config.js`:

- keep its imports and `plugins`;
- drop `proxy`, `hmr` (`clientPort` included) and `host: '0.0.0.0'` from an existing `server`;
- `server.ws` needs Vite 8.1 or later.

```js
import { fileURLToPath } from 'node:url';

// Nested worktrees and the board are other checkouts, vendor/ is ~15k inotify watches; anchored so only
// these top-level dirs are skipped.
const unwatched = ['./.claude/worktrees', './docs', './vendor'].map((d) => fileURLToPath(new URL(d, import.meta.url)));
// A browser's Origin never ends in a slash.
const appUrl = process.env.APP_URL?.replace(/\/+$/, '');

export default defineConfig({
    plugins: [/* … */],
    server: {
        // Caddy dials 127.0.0.1:5173; the entrypoint passes the same host and port with --strictPort.
        host: '127.0.0.1',
        // Written into public/hot: assets and the HMR socket go through Caddy's port. Compose sets APP_URL.
        origin: appUrl,
        // IPs, localhost and the origin's host always pass; never `true` (DNS rebinding).
        allowedHosts: ['.test'],
        // A page opened under another name than public/hot's loads assets cross-origin.
        cors: {
            origin: [
                /^https?:\/\/(?:(?:[^:]+\.)?localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/,
                /^https?:\/\/.*\.test(:\d+)?$/,
                ...(appUrl ? [appUrl] : []),
            ],
        },
        // Its own path so Caddy can route it (at `/` it would reach php-fpm).
        ws: { path: '/__vite_hmr' },
        // Pair with Caddy's @vite matcher (docker/Caddyfile.local).
        fs: { strict: true, allow: ['resources', 'node_modules'] },
        watch: {
            ignored: ['**/storage/framework/views/**', (p) => unwatched.some((d) => p === d || p.startsWith(d + '/'))],
        },
    },
});
```

The HMR client takes its scheme, host and port from the origin in `public/hot`, so no `clientPort` is set. Keep the
anchored paths: a glob such as `**/docs/**` would also skip `resources/docs`.

## phpunit.xml and tests/bootstrap.php — every `<env>` forced and mirrored

Compose sets `APP_ENV`, `DB_DATABASE` and the drivers as process env in the local container. Process env sits in
`$_SERVER`, and Laravel's `Env` reads `$_SERVER` first. PHPUnit's `<env>` reaches only `$_ENV` and `putenv()`, forced or
not. Without a fix, `php artisan test` in the container runs `RefreshDatabase` on the dev database.

The fix: `tests/bootstrap.php` (a template) copies every `force="true"` value of `phpunit.xml` into `$_SERVER`, and
`phpunit.xml` names it in `bootstrap=`. Never list `DB_HOST`, `DB_PORT` or `REDIS_*`: `.env` (host) or compose
(container) points them at this checkout's own stack.

```xml
<phpunit bootstrap="tests/bootstrap.php" …>
<php>
    <env name="APP_ENV" value="testing" force="true"/>
    <env name="APP_MAINTENANCE_DRIVER" value="file" force="true"/>
    <env name="BCRYPT_ROUNDS" value="4" force="true"/>
    <env name="BROADCAST_CONNECTION" value="null" force="true"/>
    <env name="CACHE_STORE" value="array" force="true"/>
    <env name="DB_CONNECTION" value="pgsql" force="true"/>
    <env name="DB_DATABASE" value="{{app}}_test" force="true"/>
    <env name="DB_URL" value="" force="true"/>
    <env name="MAIL_MAILER" value="array" force="true"/>
    <env name="QUEUE_CONNECTION" value="sync" force="true"/>
    <env name="SESSION_DRIVER" value="array" force="true"/>
</php>
```

## bootstrap/app.php — trusted proxies

```php
->withMiddleware(function (Middleware $middleware): void {
    // env() here is deliberate: this closure runs before config is loaded,
    // and in Docker the value is a real process env var.
    $trustedProxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));
    if ($trustedProxies !== []) {
        $middleware->trustProxies(at: $trustedProxies);
    }
})
```

`TRUSTED_PROXIES` per tier:

- local: `127.0.0.1`, set in compose (why: SKILL.md, trap "Anyone on the LAN opens `/horizon`");
- prod: the reverse proxy's address as its requests arrive in the container (`.env.prod`), plus `127.0.0.1` with spa
  (`references/spa.md`).

Caddy's own trust (`docker/Caddyfile`) decides only its `{client_ip}`, never what Laravel sees.

## .env and .env.example

The host `.env` is the host-side env: `DB_*` and `REDIS_*` reach the sidecars through their published ports. Add:

```dotenv
# docker-compose.local.yml refuses to run without it; a worktree's generated .env names its own stack.
COMPOSE_PROJECT_NAME={{app}}-local
# The local stack opened from another machine: the URL the browser uses (the container's APP_URL, the origin Vite writes into public/hot).
# LOCAL_APP_URL=http://192.0.2.10:{{web_port}}
# Only when the host user is not 1000 (id -u, id -g): the container runs as this uid, which must own the checkout and its .git.
# HOST_UID=1000
# HOST_GID=1000
# Overrides for the host addresses the published ports bind: see CLAUDE.md, Hosting (Binds).
# WEB_BIND=127.0.0.1
# SIDECAR_BIND=127.0.0.1
# Names who edits from the board page: the container has no git identity.
# KANBAN_USER=Ana
# Gates /kanban, which has no login and is LAN-visible by default (CLAUDE.md, Hosting).
# KANBAN_UI_TOKEN=
```

`.env.example` carries the same block with `COMPOSE_PROJECT_NAME` commented.

## Config and env

- Defaults live in `config/*.php`, as the `env()` default or a plain literal. `.env` files and compose `environment:`
  carry only what differs between tiers: credentials, hosts, `APP_KEY`, `APP_URL`, log level.
- The test for each variable: would two environments ever want different values? No → a config literal.
- `env()` is called only in `config/`, always with a default. The one exception is `TRUSTED_PROXIES` in
  `bootstrap/app.php`, which runs before config loads.
- One name per concern. A second meaning gets a second variable (`REVERB_HOST` connects, `REVERB_SERVER_HOST` listens).
- Fix drift on sight: `.env.example` entries nobody overrides, compose restating framework defaults, `env()` without a
  default, a variable present in one tier's env only.
- `.env.prod.example` lists every required production variable; the prod compose header repeats the list. Prod logs go
  to `stderr` (`docker logs`).
