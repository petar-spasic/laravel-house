# CLAUDE.md — Frontend (SvelteKit SPA)

Scope: everything under `frontend/`. Project-wide context (stack, the E2E-only
test rule, git rules) lives in the root `CLAUDE.md`; backend architecture
(layers, API design, validation) in `app/CLAUDE.md` and its layer files.

## Stack

- **Framework:** SvelteKit with Svelte 5, `@sveltejs/adapter-static`
- **State:** Svelte 5 runes (`$state`, `$derived`, `$effect`)
- **Forms:** Zod for validation
- **HTTP:** Axios
<!-- if:reverb -->
- **Real-time:** laravel-echo + pusher-js (Reverb client)
<!-- endif -->

Any other package needs owner approval (root `CLAUDE.md`, convention 6).

## Build and serving

- **Local:** Vite runs inside the container behind nginx (root `CLAUDE.md`,
  Hosting): browse the web port — SPA + API on one origin — and edits
  hot-reload. The HMR port carries only the HMR websocket; hitting it directly
  bypasses nginx and `/api` 404s.
- **Prod build:** `npm run build`. adapter-static emits into `frontend/build/`
  — its own directory, because it wipes its output (never Laravel's
  `public/`) — then the `postbuild` hook (`frontend/scripts/copy-spa.mjs`)
  copies the `_app/` asset tree and the `spa.html` shell into `public/`
  without touching `index.php`/`.htaccess`.
- **Serving:** same-origin with the API in EVERY environment (that's what makes
  Sanctum cookie auth work). Prod routing is `docker/Caddyfile` — PHP only for
  backend routes; deep links fall back to the shell via `Route::fallback` in
  `routes/web.php`.

## Component architecture

Shared primitives live in one directory under `frontend/src/lib/components/`
and are never modified in place — wrap them. Feature components are grouped by
domain directory, one dir per area.

## UX & design-system standards

**Never make users type identifiers.** No form may ask for an id in a text
input — every entity reference goes through the searchable `EntityPicker`
combobox, backed by the per-resource `GET {resource}/search` +
`GET {resource}/autocomplete` endpoints (the picker sends **`term` +
`limit`**, optionally id FK filter params to scope results). Closed
vocabularies (currency, country, status, …) use a Select — never free text.

Design tokens live in one CSS file, an **oklch** color system; icons come from
one icon set, **never emoji**.

Interaction baseline every surface meets:

- **Forms:** generated-Zod validation with real-time inline errors (see
  Validation below); multi-step flows use one shared wizard.
- **Loading states:** skeleton screens while data loads — never a blank pane.
- **Tables:** one shared data-table wrapper (server-side sorting, filtering,
  row selection, bulk actions via the backend `{resource}/bulk-action`
  endpoints, pagination, CSV export).
- **Search/filter:** debounced inputs, quick-filter chips + saved views,
  clear-all.
- **Feedback:** toasts for success/error, confirmation dialogs (one shared
  `confirmAction`) before destructive actions.

<!-- if:reverb -->
## Realtime (SPA side)

Channel auth, payload rules and the operational requirements are in
`app/Events/CLAUDE.md` (Broadcasting).

- **One reconnect spine.** `frontend/src/lib/realtime/connection.ts` binds the
  pusher connection state once on the Echo singleton (`echo.ts`) and exposes
  `onReconnect(cb): Unregister`, which fires only on a TRUE reconnect (a
  `connected` after a real drop, flaps debounced — never the first connect);
  `disconnectEcho()` clears the registry at logout. Every feature re-auths and
  backfills through it — never a feature-specific reconnect handler.
- **"Live" is refetch-on-event.** A list that can have gaps keeps a
  `lastSeenId` watermark and on reconnect merges the gap from a keyset
  `…/since` endpoint (dedupe by id) instead of refetching the list; incoming
  deltas merge in place too.
- **Ordering is a client guard:** every insert path (initial load, optimistic
  send, append, delta, backfill, load-older) goes through one setter that
  dedupes by id and sorts by `(created_at, id)` ascending — the server's keyset
  order.
- **Counters reconcile from server truth:** a broadcast carries the
  recipient's authoritative count; new arrivals are announced in a polite
  `aria-live` region.

<!-- endif -->
## Route protection

- Layout groups: `src/routes/(auth)/` is guarded, `src/routes/(public)/` is
  not. `(auth)/+layout.svelte` guards via `$effect` + `beforeNavigate` on
  `authStore.isAuthenticated`, redirecting to `/login`.
- Navigation is one annotated catalog, `navigationCatalog` in
  `lib/config/navigation.ts`: each item names the permission it requires
  (`null` = any signed-in user), and `deriveNavigation(user)` filters it,
  dropping empty sections. A zero-permission user sees the empty-but-safe shell
  (real identity, no feature nav).

## API client

- One axios instance, `frontend/src/lib/api/client.ts`: `baseURL` `/api/v1`,
  `withCredentials` + `withXSRFToken`. The CSRF bootstrap
  (`initializeSanctum`, `/sanctum/csrf-cookie`) runs before the first
  authenticated request.
- **Only the authoritative `/auth/me` probe clears auth** in the shared 401/419
  interceptor; an incidental 401/419 on any other call rejects to its caller
  untouched — a raced post-login call must never bounce a fresh login back to
  `/login`.
- `login()` (`lib/api/auth.ts`) settles the regenerated session with ONE serial
  `GET /auth/me` (after the CSRF re-prime) before returning, so the parallel
  post-login burst never meets a half-written session.
- Speculative probes — the `/login`, `/`, `/register` guards, where a 401 is the
  expected answer — call `probeSession()`, which flags the request so the
  interceptor skips `clearAuth()`. Never exempt by pathname: during a SvelteKit
  load `window.location.pathname` and `page.url` still hold the previous route,
  and `clearAuth()` → `goto('/login')` → 401 then loops on `/auth/me`.
  `clearAuth()` redirects only when a session was actually torn down;
  `(auth)/+layout.ts` keeps plain `me()`, where a 401 is a real teardown.

## Validation (frontend side)

Runtime Zod schemas are GENERATED from backend FormRequests — never
hand-maintained. An export command writes descriptors to
`public/dist/validation/*.json`; `zodFromDescriptor` / `FormController` in
`frontend/src/lib/validation/schema.ts` turn them into runtime schemas. To
change validation: edit the FormRequest (backend), re-export. Both halves are
built with the first form.

## Accessibility & keyboard navigation

WCAG 2.1 AA + full keyboard navigation are required. Modal focus trapping comes
from the dialog primitive's built-in focus management; an overlay opened by a
global keyboard shortcut (no trigger element to restore) captures
`document.activeElement` on open and refocuses it on close. Every interactive
element needs an accessible name; dynamic changes announce via live regions;
contrast ≥4.5:1 (normal) / ≥3:1 (large text & UI); skip links on every page.
