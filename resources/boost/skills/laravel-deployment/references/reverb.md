# Reverb — the `reverb` module only

Without the module there is no Reverb program, port, healthcheck line or `REVERB_*` env: a Reverb program in an app
that does not broadcast keeps the container unhealthy for good.

## One server, three addresses

Conflating them is the recurring Reverb bug. Each concern has its own name, defaulted in config:

| Address | Who dials it | Set in |
|---|---|---|
| Listen | the Reverb server binds | `config/reverb.php` server: `REVERB_SERVER_HOST` 0.0.0.0, `REVERB_SERVER_PORT` 8081 — Octane and nginx own 8080 in both images |
| Connect | the broadcaster publishing events, same container | `config/broadcasting.php` reverb options: `REVERB_HOST` 127.0.0.1, `REVERB_PORT` 8081, `REVERB_SCHEME` http |
| Browser | Echo in the page | server-rendered pages: `REVERB_PUBLIC_HOST/PORT/SCHEME`, read at runtime; a static SPA: `VITE_REVERB_*`, baked in at build time |

Behind TLS the browser address is `<public host>/443/https` (Echo turns https into wss). `REVERB_APP_ID/KEY/SECRET`
match on server, broadcaster and client.

## Template additions

`{{ws_port}}` is main's host port for Reverb, chosen with the other ports.

- Both entrypoints: `program reverb "php artisan reverb:start"` (listens on the config defaults).
- `docker/healthcheck.sh`: `pgrep -f "artisan reverb:start" >/dev/null || { echo "✗ reverb"; FAILED=1; }`.
- Both Dockerfiles: `8081` on the `EXPOSE` line.
- `docker-compose.local.yml`: `- "${WS_PORT:-{{ws_port}}}:8081"` under `ports`; `WS_PORT` is one more worktree port
  (a `stack.ports` offset in `config/kanban.php`, laravel-kanban README).
- `docker-compose.yml`: `- "127.0.0.1:${WS_PORT:-{{ws_port}}}:8081"`; the host's reverse proxy sends the public
  WebSocket host (or the `/app/*` and `/apps/*` paths) there.
- `.env.prod.example`: `REVERB_APP_ID=`, `REVERB_APP_KEY=`, `REVERB_APP_SECRET=`, `REVERB_PUBLIC_HOST={{domain}}`,
  `REVERB_PUBLIC_PORT=443`, `REVERB_PUBLIC_SCHEME=https`.
- SPA: each `VITE_REVERB_*` is an `ARG` → `ENV` in the node stage, fed by compose `build.args`; changing one means a
  rebuild.
