# SPA and API-only apps

For the `spa` module (a static-built SvelteKit/React/Vue app) and for API-only apps: no Vite front door locally, and
for the SPA, Caddy serves the shell and assets without PHP.

## Same origin in every tier

Serve the SPA from the API's origin everywhere: Sanctum cookie auth then needs no CORS. The client calls the API
relative (`''`); `VITE_API_URL` exists only for SSR/prerender.

## Local

- Publish nginx instead of Vite: `- "${WEB_PORT:-{{web_port}}}:8080"`; drop `program vite` from the local entrypoint
  and the `:5173` line from `docker/healthcheck.sh`.
- The local entrypoint builds the SPA when the shell is missing or `SPA_BUILD=1` (PHP is there for npm hooks); a
  frontend change needs a rebuild:
  `docker compose -f docker-compose.local.yml exec app bash -lc 'cd frontend && npm run build'`.
- A PHP `Route::fallback` serves the shell (with the same missing-asset 404) wherever Caddy does not run: the local
  nginx image.

## Build and placement

- The SPA builds into its own directory (`frontend/build/`), never Laravel's `public/`: adapters wipe their output
  directory. The node stage builds it; the runtime stage copies the hashed asset tree (`_app/`, `assets/`) and the
  shell (`spa.html`) into `public/`.
- `VITE_*` values are frozen into the bundle at build time: each browser-facing one is an `ARG` → `ENV` in the node
  stage, fed by compose `build.args` (behind TLS: `<public host>/443/https`).
- Large builds exhaust Node's heap ("Ineffective mark-compacts near heap limit"):
  `ENV NODE_OPTIONS=--max-old-space-size=8192` in the node stage.

## Prod Caddyfile — PHP only for backend routes

Replace the `route { … }` block of `docker/Caddyfile`; every backend prefix the app has (Fortify's `prefix`,
webhooks) joins `@backend`:

```caddyfile
	route {
		root * "{$APP_PUBLIC_PATH}"
		encode zstd br gzip

		# Hashed SPA assets never change under the same name.
		header /_app/immutable/* Cache-Control "public, max-age=31536000, immutable"

		{$CADDY_SERVER_EXTRA_DIRECTIVES}

		@backend path /api/* /sanctum/* /broadcasting/* /horizon* /up
		handle @backend {
			php_server {
				index frankenphp-worker.php
				try_files {path} frankenphp-worker.php
				resolve_root_symlink
			}
		}

		@realfile file
		handle @realfile {
			file_server
		}

		# A missing file under an asset prefix is a 404, never the shell: HTML served for a
		# mistyped JS URL hides a broken build.
		@missingasset path /_app/* /assets/*
		handle @missingasset {
			error 404
		}

		# Everything else is a client-side route: the shell, straight off disk.
		handle {
			rewrite * /spa.html
			file_server
		}
	}
```
