Two tiers, **same stack, different web server**. Both run Postgres 16, Redis 7,
Horizon and the scheduler; the only thing that differs is what serves PHP:

| Tier | Files | Server | Why |
|------|-------|--------|-----|
| **local** | `Dockerfile.local`, `docker-compose.local.yml` | nginx + php-fpm + Vite HMR + Xdebug, scheduler + Horizon in the same container | Fresh PHP process per request so **Xdebug breakpoints work** — debugging under FrankenPHP's warm workers is effectively impossible. Bind-mounted source; deps install on start (sha256-sentinel-guarded). |
| **prod** | `Dockerfile`, `docker-compose.yml`, `.env.prod` | **FrankenPHP + Octane** as www-data, `schedule:work` and `horizon` in the same container under supervisor | Long-running workers, fast reads. |

**The Docker local stack IS the dev environment** — not `composer run dev`,
not sqlite. The host-side `.env` points at the sidecars through their
published ports (main {{db_port}}/{{redis_port}}; a worktree: the ports its generated `.env`
names), so `php artisan …`, `php artisan test` and tinker on the host see
exactly the data that checkout's container serves. Never introduce a driver
difference between local and prod (sqlite here, Postgres there; `sync` queue
here, Redis there) — that is how "works locally" bugs are born. The E2E suite
runs on the same Postgres (`{{app}}_test`, created by
`docker/postgres/init-test-db.sql` on a fresh volume); `phpunit.xml` forces the
test database and drivers and `tests/bootstrap.php` mirrors them into `$_SERVER` (where compose's env would
otherwise win), so `php artisan test` is safe on the host and in the container.

```
docker compose -f docker-compose.local.yml up --build          # http://localhost:{{web_port}} (Vite front → nginx), /horizon
cp .env.prod.example .env.prod && docker compose --env-file .env.prod up -d --build
```

- **Stack names come from `COMPOSE_PROJECT_NAME` in `.env`** (compose refuses
  to start without it): main is `{{app}}-local` → containers
  `{{app}}-local-{app,postgres,redis}-1`. Never add `container_name`, a
  volume/network `name:` or `image:` to `docker-compose.local.yml`.
- **Worktree stacks**: each `.claude/worktrees/<name>` runs its own stack
  `{{app}}-wt-<name>` from the same compose file, with a generated `.env` (own
  ports, `SIDECAR_BIND=127.0.0.1`, Xdebug off) — `vendor/bin/kanban stack create`.
- **Opened from another machine**: `LOCAL_APP_URL=http://<LAN address>:{{web_port}}`
  in `.env`. Compose passes it as the container's `APP_URL`, which Vite writes
  into `public/hot` as the one asset origin — with `localhost` there, the page
  loads but every asset fails with `ERR_CONNECTION_REFUSED`.
- **PHP {{php_version}} everywhere** — the host (tests, artisan), the lock and both images.
- **Boot** (`docker/docker-entrypoint*.sh`): deps (local), rebuild the package
  manifest (never trust a `bootstrap/cache` from another image; only `storage/` is
  a volume), clear config/routes/events/views (local), wait for the database,
  migrate once and loudly, seed only as `DATABASE_SEED` says (`database/CLAUDE.md`),
  cache config/routes/events/views (prod), then supervisor.
- **Queues are Horizon's** (`config/horizon.php`), never `queue:work`: Redis
  `retry_after` (`REDIS_QUEUE_RETRY_AFTER`, 90 s) > Horizon's job `timeout`
  (60 s) > the longest job. A longer job raises both, in that order, plus
  supervisor's `stopwaitsecs` for Horizon (70 s) and the compose
  `stop_grace_period` (80 s), so SIGTERM lets an in-flight job finish.
- `docker/healthcheck.sh` checks `/up` and every process: the web server
  (Octane, or nginx + php-fpm + Vite locally), the scheduler, Horizon, supervisord.
- Prod publishes `127.0.0.1:${WEB_PORT}` only: TLS terminates at a reverse
  proxy on the host, trusted through `TRUSTED_PROXIES` (`bootstrap/app.php`,
  `docker/Caddyfile`). `OCTANE_WORKERS` × `PHP_MEMORY_LIMIT` fits the box's RAM.
- Xdebug: `xdebug.start_with_request=trigger` — set the `XDEBUG_TRIGGER`
  cookie / query param and map `/app` → the repo root in the IDE
  (`PHP_IDE_CONFIG=serverName={{app}}`; `XDEBUG_MODE` / `XDEBUG_SERVER_NAME`
  in `.env` override); CLI processes in the container use the same mapping.
