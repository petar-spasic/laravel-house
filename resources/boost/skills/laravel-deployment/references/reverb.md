# Reverb — the `reverb` module only

Without the module there is no Reverb program, port, healthcheck line or `REVERB_*` env: a Reverb program in an app
that does not broadcast keeps the container unhealthy for good.

## One server, three addresses

Conflating them is the recurring Reverb bug. Stock `install:broadcasting` writes `REVERB_HOST=localhost`,
`REVERB_PORT=8080` and the framework's broadcaster is `null`, so nothing below exists until the module sets it; 8080
belongs to nginx (local) or Octane (prod).

| Address | Who dials it | Set |
|---|---|---|
| Listen | the Reverb server binds | `REVERB_SERVER_HOST=0.0.0.0`, `REVERB_SERVER_PORT=8081` (read by `config/reverb.php`) |
| Connect | the broadcaster publishing events, same container | `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8081`, `REVERB_SCHEME=http` (read by `config/broadcasting.php`), plus `BROADCAST_CONNECTION=reverb` |
| Browser | Echo in the page | server-rendered pages: `REVERB_PUBLIC_HOST/PORT/SCHEME`, read at runtime; a static SPA: `VITE_REVERB_*`, baked in at build time |

Where: the local compose `environment:` (container wiring is hardcoded there, the host `.env` keeps its own `REVERB_*`)
and `.env.prod.example`, both below. A separate `REVERB_PUBLIC_*` keeps the browser address from being confused with
the connect address.

Behind TLS the browser address is `<public host>/443/https` (Echo turns https into wss). `REVERB_APP_ID/KEY/SECRET`
match on server, broadcaster and client.

## Template additions

`{{ws_port}}` is main's host port for Reverb, chosen with the other ports.

- Both entrypoints: `program reverb "php artisan reverb:start"` (listens on `REVERB_SERVER_*`).
- `docker/healthcheck.sh`: `pgrep -f "artisan reverb:start" >/dev/null || { echo "✗ reverb"; FAILED=1; }`.
- Both Dockerfiles: `8081` on the `EXPOSE` line.
- `docker-compose.local.yml`: `BROADCAST_CONNECTION: reverb`, `REVERB_SERVER_HOST: 0.0.0.0`, `REVERB_SERVER_PORT: 8081`,
  `REVERB_HOST: 127.0.0.1`, `REVERB_PORT: 8081`, `REVERB_SCHEME: http` under `environment`;
  `- "${WEB_BIND:-0.0.0.0}:${WS_PORT:-{{ws_port}}}:8081"` under `ports`; `WS_PORT` is one more worktree port
  (a `stack.ports` offset in `config/kanban.php`, laravel-kanban README).
- `docker-compose.yml`: `- "127.0.0.1:${WS_PORT:-{{ws_port}}}:8081"`; the host's reverse proxy sends the public
  WebSocket host (or the `/app/*` and `/apps/*` paths) there.
- `.env.prod.example`: `BROADCAST_CONNECTION=reverb`, `REVERB_SERVER_HOST=0.0.0.0`, `REVERB_SERVER_PORT=8081`,
  `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8081`, `REVERB_SCHEME=http`, `REVERB_APP_ID=`, `REVERB_APP_KEY=`,
  `REVERB_APP_SECRET=`, `REVERB_PUBLIC_HOST={{domain}}`, `REVERB_PUBLIC_PORT=443`, `REVERB_PUBLIC_SCHEME=https`.
- SPA: each `VITE_REVERB_*` is an `ARG` → `ENV` in the node stage, fed by compose `build.args`; changing one means a
  rebuild.
