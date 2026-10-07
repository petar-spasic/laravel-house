@houserules('htmx|spa')
@houserules('htmx')
## Non-negotiables — SPEED, SEO, SMOOTHNESS

Every decision weighs these three, in this order. A change that regresses one is a bug, whatever it ships.

1. **Speed.** Public pages are served from the response cache, not computed. Nothing render-blocking from third
   parties.
2. **SEO.** Every public page is **complete without JavaScript**: server-rendered, real URLs and `<a href>` links,
   canonical, title and meta description, structured data, sitemap, sane pagination. A crawler and a user on a 3G
   phone see the same page.
3. **Smoothness.** No layout shift, no View Transitions, no spinner on a fast request, no full-page reload for
   in-app navigation.

**HTML is the asset we cache and prefetch**, always at both levels:

- The **response cache** serves anonymous GETs of public pages from Redis, with `ETag` and a public `Cache-Control`.
  It is **invalidated on write by tag**, never by TTL alone (`app/Http/CLAUDE.md`).
- A **fragment cache** (`Cache::flexible`) under it keeps a miss cheap.
- The next page is **prefetched** before the click (`resources/CLAUDE.md`).

@houserules('islands')
**JavaScript policy:** a page loads the shared `app.ts` bundle (htmx + islands boot) and nothing else. Behaviour is
**htmx + Blade first**. A Svelte island is used only when the htmx version would be a tangle of `hx-*` attributes and
partial routes, never because JS would be nicer (`resources/CLAUDE.md`).
@endhouserules
@unlesshouserules('islands')
**JavaScript policy:** a page loads the shared `app.ts` bundle (htmx) and nothing else. Behaviour is **htmx +
Blade** (`resources/CLAUDE.md`).
@endhouserules

@endhouserules
@houserules('htmx')
## Frontend — Blade + htmx

The rules are in `resources/CLAUDE.md`. Two bind every layer:

- **Server-rendered HTML is the API.** A controller returns a Blade view: the fragment for an htmx request
  (`HX-Request`), else the full page. **Both render the same partial (or `@fragment`)**; never two copies of the
  markup.
- **One CSP header disallows inline script** (`SecurityHeaders`). So `htmx.config.allowEval = false`: no `hx-on:*`,
  no `hx-vals='js:…'`, no inline `<script>` or `onclick`. Behaviour lives in `resources/js/`, bundled by Vite.

@endhouserules
@houserules('spa')
## Frontend — SvelteKit

The rules are in `frontend/CLAUDE.md`. The processes, Caddy matchers and health checks are in Hosting.

### Surfaces: who answers which path

Code goes where its path says. The matchers are in `docker/Caddyfile` and `docker/Caddyfile.local`; the middleware
groups in `routes/CLAUDE.md`.

| Path | Answered by | Surface |
|------|-------------|---------|
| `/api/v1/*` | Laravel, `api` group | Resources for every client: the browser's session cookie or a token, `auth:sanctum` |
| `/api/auth/*` | Laravel, `web` group | Fortify (headless, JSON) and Socialite's redirect and callback |
| `/sanctum/*` | Laravel | the browser's CSRF cookie |
| `/horizon`, `/horizon/*`, `/up` | Laravel | operators; Laravel's health |
| `/storage/*` | Laravel | files on the public disk |
| `/kanban`, `/kanban/*` | Laravel, local only | the board page |
@houserules('reverb')
| `/api/broadcasting/auth` | Laravel, `api` group | Echo's channel auth, `auth:sanctum` (`routes/CLAUDE.md`, `channels`) |
| `/app/*` | Reverb | Echo's WebSocket, on the page's own origin |
@endhouserules
| files in `build/client`, prerendered pages | Caddy, off disk (prod and the e2e site) | public, no session |
| a missing `/_app/immutable/*` | Caddy, 404 | never reaches Node |
| `/healthz` | Caddy, 404 from outside | SvelteKit's `handle` answers it on 127.0.0.1:3000 for the healthcheck |
| everything else | SvelteKit | pages, form actions |

- **Laravel is reached only over HTTP**, on those paths. The browser calls it directly; server-side code goes through
  Caddy's internal address.
- **Every response is a Resource and every rule a FormRequest.** The SvelteKit app's Zod schemas are generated from
  the FormRequests (`app/Requests/CLAUDE.md`), never hand-written.

@endhouserules
@endhouserules
