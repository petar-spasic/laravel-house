# Project files the stack needs

Merge these into the skeleton's files; `{{app}}`, `{{web_port}}` as in SKILL.md.

## vite.config.js — the local front door (htmx, islands)

Vite runs in the local container on 5173, published as the web port, and proxies everything it does not own to nginx
on 8080. Keep the project's `plugins`; add the rest:

```js
import { fileURLToPath } from 'node:url';

// Nested worktrees and the board are other checkouts; anchored so only these two top-level dirs are skipped.
const unwatched = ['./.claude/worktrees', './docs'].map((d) => fileURLToPath(new URL(d, import.meta.url)));

export default defineConfig({
    plugins: [/* … */],
    server: {
        host: '0.0.0.0',
        // The one asset origin written into public/hot: compose sets APP_URL from LOCAL_APP_URL.
        origin: process.env.APP_URL,
        // Dev-only server behind a LAN proxy; nginx does the real serving.
        allowedHosts: true,
        // Opening the stack under another host name (localhost vs the LAN name) makes assets cross-origin.
        cors: true,
        hmr: process.env.HMR_CLIENT_PORT ? { clientPort: Number(process.env.HMR_CLIENT_PORT) } : undefined,
        proxy: {
            '/': {
                target: 'http://127.0.0.1:8080',
                // Pass the real client on; Laravel trusts X-Forwarded-For only from 127.0.0.1 (compose TRUSTED_PROXIES).
                xfwd: true,
                bypass: (req) => (/^\/(@|resources\/|node_modules\/|__vite)/.test(req.url ?? '') ? req.url : undefined),
            },
        },
        watch: {
            ignored: ['**/storage/framework/views/**', (p) => unwatched.some((d) => p === d || p.startsWith(d + '/'))],
        },
    },
});
```

A glob such as `**/docs/**` would also skip `resources/docs`: keep the anchored paths.

## phpunit.xml — every `<env>` forced

Compose sets `APP_ENV`, `DB_DATABASE` and the drivers as process env in the local container. An `<env>` without
`force="true"` loses to the process env, so `php artisan test` in the container would run `RefreshDatabase` on the dev
database. `DB_HOST`, `DB_PORT` and `REDIS_*` are never listed: `.env` (host) or compose (container) point them at this
checkout's own stack.

```xml
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
    $trustedProxies = array_filter(explode(',', (string) env('TRUSTED_PROXIES', '')));
    if ($trustedProxies !== []) {
        $middleware->trustProxies(at: $trustedProxies);
    }
})
```

Local: Vite's proxy, `127.0.0.1` (compose). Prod: the reverse proxy's addresses (`.env.prod`); Caddy trusts private
ranges on its own (`docker/Caddyfile`).

## .env and .env.example

The host `.env` is the host-side env: `DB_*`/`REDIS_*` reach the sidecars through their published ports. The compose
file adds:

```dotenv
# docker-compose.local.yml refuses to run without it; a worktree's generated .env names its own stack.
COMPOSE_PROJECT_NAME={{app}}-local
# The local stack opened from another machine: the URL the browser uses (the container's APP_URL, the Vite origin).
# LOCAL_APP_URL=http://192.0.2.10:{{web_port}}
# Only when the host user is not 1000 (id -u, id -g): www-data in the local image takes these.
# HOST_UID=1000
# HOST_GID=1000
```

`.env.example` carries the same block with `COMPOSE_PROJECT_NAME` commented.
