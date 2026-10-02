# Modules

What each module installs (step 3), wires (step 6) and checks (step 8). Which modules combine is the SKILL.md Modules
table.

## htmx

- **Writes:** `resources/CLAUDE.md`, `resources/js/app.ts`, `htmx-global.ts`, `global.d.ts` and `tsconfig.json`.
- **Packages:** `htmx.org`, `htmx-ext-preload` (htmx 2 ships its extensions separately), `typescript`, and
  `vite@^8.1`.
  - With islands, it is `typescript@^6`: svelte-check does not accept TypeScript 7.
  - Vite 8.1 is the first with `server.ws`, which the local Caddy routes the HMR socket by (laravel-deployment
    `references/project-files.md`).
- **Deletes:** `resources/js/app.js`.
- **Wiring:**
  - `vite.config.js` `input` and the `@vite` line in `welcome.blade.php` name `resources/js/app.ts`;
  - `package.json` gets `"check": "tsc"`;
  - the rendered `htmx-indicator.css` is appended to `resources/css/app.css`;
  - `config/{{app}}.php` holds the keys `routes/CLAUDE.md` and `app/Http/CLAUDE.md` name: `public_per_minute`,
    `page_cache.hard_ttl`, `page_cache.cache_control`, `fragment_targets`, `route_query_keys`;
  - `tests/TestCase.php` `setUp()` calls `$this->withoutVite()` (`project-wiring.md`, Tests).
- `CachePublicResponse` and `HtmxOnly` are project code the rules describe, not shipped files.
  `Route::middleware('public')` resolves only once the project adds them.
- Fortify's views stay off until the project builds its Blade pages (`references/core-auth.md`).
- **Checks:** `npm run check` and `npm run build`.

## islands

- **Writes:** `resources/js/islands.ts`, `svelte.config.js`, `resources/views/components/island.blade.php`, and the
  mount lines in `app.ts`.
- **Packages:** `svelte`, `@sveltejs/vite-plugin-svelte` and `svelte-check`. TypeScript is pinned under htmx.
- shadcn-svelte is not installed at setup. The islands rules carry the pinned `add` command and the pinned registry
  for the first component an island needs. Never run its `init`.
- **Wiring:** `svelte()` in the Vite plugins, and `"check": "svelte-check --tsconfig ./tsconfig.json"`.
- **Checks:** as htmx.

## spa

- **Writes:** `frontend/CLAUDE.md` only.
- **Packages:** none. No `sv create` and no npm install at setup. The stub's "First frontend change" holds the exact
  command and the pinned packages for the first card that needs a page.
- **Deletes** the root Node toolchain, which nothing uses:
  - `package.json` and `package-lock.json`;
  - `vite.config.js`, `resources/js/` and `resources/css/`;
  - `resources/views/welcome.blade.php` and its `/` route in `routes/web.php`;
  - `public/favicon.ico` and `public/robots.txt`;
  - composer.json's `dev` script and the npm lines in its other scripts.
- **Wiring:** Fortify under `/api/auth` and the session-cookie API (`references/core-auth.md`).
- **Checks:** none until `frontend/` exists. Then `npm run check` in `frontend/`, which runs `validation:export --check`
  first (`references/validation-export.md`). It defaults `PUBLIC_APP_URL`, so it also runs on the host: with the
  board, `cd frontend && npm run check` is a `gates.report` entry in `config/kanban.php`.

## reverb

- **Command:**
  - without spa: `php artisan install:broadcasting --reverb`. It adds `laravel-echo` and `pusher-js` to the root
    `package.json`;
  - with spa: `php artisan install:broadcasting --reverb --without-node`. Then delete the `VITE_*` lines it appended
    to `.env`: the SvelteKit app reads the key at runtime.
- Its stock `REVERB_*` values and `BROADCAST_CONNECTION` are wrong for the container. laravel-deployment's
  reverb blocks set them (step 9; why: its `references/reverb.md`).
- With spa, the frontend installs `laravel-echo` and `pusher-js` in its first change (`frontend/CLAUDE.md`).
- With spa, `bootstrap/app.php` takes the rendered snippet's `withBroadcasting(…)` call and drops the `channels:`
  argument `install:broadcasting` adds to `withRouting`.

## tenancy

- **Writes** no files and installs no packages.
- Its rules are `## Tenancy` in the root `CLAUDE.md` and the `if:tenancy` blocks in the layer stubs.
- The database roles, the owner connection and the test migrations: laravel-deployment `references/tenancy.md`
  (step 9).

## API-only (neither htmx nor spa)

- It keeps the skeleton's root Node toolchain.
- **Command:** `npm install`, so `package-lock.json` exists. Both images run `npm ci`, and the local boot stops on a
  `package.json` without a lockfile.
- **Checks:** none beyond SKILL.md step 8.
