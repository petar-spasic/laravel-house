# Reverb — the `reverb` module only

Without the module there is no Reverb program, port, healthcheck line or `REVERB_*` env. A Reverb program in an app
that does not broadcast keeps the container unhealthy for good.

## One server, three addresses

Conflating these is the recurring Reverb bug. Stock `install:broadcasting` writes `REVERB_HOST=localhost` and
`REVERB_PORT=8080`, and the framework's broadcaster is `null`: nothing below exists until the module sets it. 8080
belongs to Caddy (local) or Octane (prod).

| Address | Who dials it | Set |
|---|---|---|
| Listen | the Reverb server binds | `REVERB_SERVER_HOST=0.0.0.0`, `REVERB_SERVER_PORT=8081` (read by `config/reverb.php`) |
| Connect | the broadcaster publishing events, same container | `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8081`, `REVERB_SCHEME=http` (read by `config/broadcasting.php`), plus `BROADCAST_CONNECTION=reverb` |
| Browser | Echo in the page | server-rendered pages: `REVERB_PUBLIC_HOST/PORT/SCHEME`, read at runtime; spa: the page's own origin (below) |

- The values go in the local compose `environment:` and in `.env.prod.example` (below). The local compose hardcodes
  the container wiring; the host `.env` keeps its own `REVERB_*`.
- A separate `REVERB_PUBLIC_*` keeps the browser address apart from the connect address. Behind TLS it is
  `<public host>/443/https`; Echo turns https into wss.
- `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` match on server, broadcaster and client.

## Template additions

`{{ws_port}}` is main's host port for Reverb, chosen with the other ports. Both Dockerfiles copy the entrypoint and
the healthcheck into the image. On a running stack, apply these with `up -d --build`, never a bare `up -d`.

- Both entrypoints: `program reverb "php artisan reverb:start"` beside `program horizon` (it listens on
  `REVERB_SERVER_*`).
- `docker/healthcheck.sh`: `pgrep -f "artisan reverb:start" >/dev/null || { echo "✗ reverb"; FAILED=1; }`.
- Both Dockerfiles: `8081` on the `EXPOSE` line.
- `docker-compose.local.yml`: `BROADCAST_CONNECTION: reverb`, `REVERB_SERVER_HOST: 0.0.0.0`, `REVERB_SERVER_PORT: 8081`,
  `REVERB_HOST: 127.0.0.1`, `REVERB_PORT: 8081`, `REVERB_SCHEME: http` under `environment`;
  `- "${WEB_BIND:-0.0.0.0}:${WS_PORT:-{{ws_port}}}:8081"` under `ports`. `WS_PORT` is one more worktree port (a
  `stack.ports` offset in `config/kanban.php`; house README, "Worktree Stacks", "Preparing Your Compose File").
- `docker-compose.yml`: `- "127.0.0.1:${WS_PORT:-{{ws_port}}}:8081"`. The host's reverse proxy sends the public
  WebSocket host (or the `/app/*` and `/apps/*` paths) there.
- `.env.prod.example`: `BROADCAST_CONNECTION=reverb`, `REVERB_SERVER_HOST=0.0.0.0`, `REVERB_SERVER_PORT=8081`,
  `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8081`, `REVERB_SCHEME=http`, `REVERB_APP_ID=`, `REVERB_APP_KEY=`,
  `REVERB_APP_SECRET=`, `REVERB_PUBLIC_HOST={{domain}}`, `REVERB_PUBLIC_PORT=443`, `REVERB_PUBLIC_SCHEME=https`.

## With spa

Echo connects to the page's own origin, and Caddy sends `/app/*` to Reverb. The SvelteKit app's CSP (`connect-src
'self'`) is baked in at build and would block a socket to another port. The additions above apply, except:

- Caddy: add the `@reverb` lines of `references/spa.md` to `docker/Caddyfile.local` and `docker/Caddyfile`, before the
  SvelteKit fallback. Reverb owns `/app` on the page's origin, so no SvelteKit route lives under it.
- No compose file publishes the WebSocket port, and no Dockerfile exposes 8081. There is no `WS_PORT` and no
  `REVERB_PUBLIC_*`. In prod the outer proxy forwards WebSocket upgrades for the public host to the web port.
- The key reaches the browser from a SvelteKit server load that reads `REVERB_APP_KEY` at runtime, never from a build
  argument. In prod it comes from `.env.prod` through the container env. Locally, add
  `REVERB_APP_KEY: ${REVERB_APP_KEY:?REVERB_APP_KEY unset in .env}` to the app `environment`, and `REVERB_APP_KEY` to
  the header's substitutable list as the one `.env` name there (the same value on both sides).
- Channel auth is `POST /api/broadcasting/auth`, inside `@backend`. It comes from the `withBroadcasting(…)` call in
  laravel-project-setup's bootstrap-app snippet. Remove the `channels:` argument that `install:broadcasting` adds to
  `withRouting`: it registers the default path, outside `@backend`.
- Browser tests open no socket: the Playwright webServer env sets `REVERB_APP_KEY: ''`, and the e2e site runs
  `BROADCAST_CONNECTION=null`.
