# CLAUDE.md — app/Http (framework HTTP layer)

Scope: `app/Http/`. This directory holds ONLY framework-level HTTP plumbing —
middleware, the base `Controller.php`, and shared controller `Concerns/`. The
code design standard is `app/CLAUDE.md`; frontend rules (escaping, CSP, CSRF)
are in the root `CLAUDE.md`.

## Hard rules

- **Every request is validated in its own FormRequest.** No inline
  `$request->validate([...])`, no `Validator::make` in controllers, no rules
  spread across actions. One endpoint ⇒ one FormRequest carrying ALL of its
  rules.
- **Every JSON response returns its own API Resource.** No `->toArray()`, no raw
  model returns, no hand-built response arrays from controllers. The Resource is
  the sensitive-data leak boundary — a raw model return leaks hidden columns.
  Collections and pagination go through the Resource too (`{data, meta}`).
<!-- if:htmx -->
- **HTML responses are Blade views or fragments.** A controller decides
  full-page vs fragment in ONE place — the base `Controller` (or a `Concerns/`
  trait) exposes the helper that checks `HX-Request` and picks the layout; no
  action re-implements that check. Templates render explicit fields; never
  serialize a whole model into the page.
<!-- endif -->
- **Placement:** FormRequests live in `app/Requests/`, Resources in
  `app/Resources/`. Do NOT create `app/Http/Requests/` or `app/Http/Resources/`
  — this app is flat by layer, and a second home for either would fragment them.
- **Every route and action serves exactly one surface** (`routes/CLAUDE.md`);
  the surfaces have different trust levels.

<!-- if:htmx -->
## HTML caching (root `CLAUDE.md`, Non-negotiables)

- **`CachePublicResponse`** (own, first in the `public` group — a hit never
  reaches the limiter) serves anonymous `GET`/`HEAD` from the default cache
  store (Redis; array in tests): key = `page:{locale}:{build}:{sha256(normalised URL)}`
  (`build` = the Vite manifest hash, so a deploy retires every page that names the old asset files)
  (`Services/Cache/PageCache::normalise`: host + path without trailing slash,
  sorted query minus `utm_*`/`fbclid`/`gclid`/`ref`, `:hx` for htmx callers plus
  `:{target}` when `HX-Target` names an allow-listed fragment id
  (`{{app}}.fragment_targets`) — a boosted full-page response and a targeted
  fragment response never share an entry; query keys kept per route from
  `{{app}}.route_query_keys`, anything else maps onto the canonical entry).
  Stores `200 text/html` and tagged `301`s (status + `Location` in
  `PageEntry`), never with cookies or `no-store`, never when `$request->user()`
  exists, never a `HEAD` (decorated, not stored). `ETag` = sha256 of the final body; `If-None-Match`
  → `304`. A prefetch/prerender miss (`Sec-Purpose`, `HX-Preloaded`) renders and is
  stored like any request — it warms the entry (a miss is ~20 ms; hover
  eagerness and `throttle:public` bound the volume).
- **Invalidation is by versioned tag, not by `Cache::tags()->flush()`.**
  Model events are wired: `Services/Cache/InvalidatesPages::register()`
  (AppServiceProvider) bumps the entity's own tag plus the reference tags on
  every `saved`/`deleted` of an admin-editable model. **Bulk upserts
  bypass model events** — they invalidate through a listener on the event that
  closes the bulk write (wired explicitly in `AppServiceProvider`; event
  discovery is off in `bootstrap/app.php`), warming in chunked jobs. An
  entry records `{tag => version}` from `Services/Cache/CacheVersions` at store
  time and is stale once any moved; a write calls `CacheVersions::bump([...])`
  (one `INCR` per tag, whatever the page count). Reason: Laravel keys tagged
  items by tag namespace, so a URL-only lookup cannot read them, and a
  wildcard flush would walk every key. Stale entries die by LRU;
  `page_cache.hard_ttl` is the safety net.
- **The CSP nonce is stored with the entry** and restored on a hit
  (`Vite::useCspNonce($stored)`; `SecurityHeaders` reads the nonce after
  `$next`) so header and cached markup agree. A public page's nonce therefore
  lives as long as its cache entry.
- Headers on hit and miss, from `config/{{app}}.php`: `Cache-Control: public,
  max-age=60, s-maxage=300, stale-while-revalidate=3600`, `ETag`, `Vary:
  HX-Request, HX-Target, Accept-Encoding`, `X-Cache: HIT|MISS`. `PageMeta::$ttl` caps
  them per page (`max-age=ttl`, `s-maxage`/`swr` = `2·ttl`).
- **Under the page cache:** `Services/Cache/FragmentCache::remember(name, tags, fn)`
  wraps every slow aggregate in `Cache::flexible` on a key that embeds the tags'
  current versions (`frag:{name}:v{versions}`), so a bulk write never flushes.
  **Cached values are plain data** — Laravel 13's `config/cache.php`
  `serializable_classes => false` makes the Redis store hand back any object as
  `__PHP_Incomplete_Class`, while the test store passes objects through;
  `FragmentCache` throws on objects so the suite catches it. DTOs carry
  `toArray()`/`fromArray()`. Fragment routes pass a `PageMeta` too (tags only),
  or their cache entry would live on TTL alone.
- Tags and ttl reach the middleware through `$request->attributes['page_meta']`,
  set by `Controller::render()` from `$data['meta']`.
- Authenticated responses are `Cache-Control: private, no-store`. No
  exceptions; a personalised page in a shared cache is a data leak.
<!-- endif -->
<!-- unless:htmx -->
## Caching

- Authenticated responses are `Cache-Control: private, no-store`. No
  exceptions; a personalised response in a shared cache is a data leak.
<!-- endif -->

## Registered (`bootstrap/app.php`)

The house names — create each under this name when it is first needed:

- `RequestId` prepended globally; `SecurityHeaders` appended globally (the
  Horizon path is exempt — its dashboard is an inline-script SPA behind the
  gate).
<!-- if:htmx -->
  The CSP nonce is `Vite::useCspNonce()` per request, read in Blade with
  `Vite::cspNonce()`; only the JSON-LD tag and Vite's own tags use it.
- Group `public` = `CachePublicResponse`, `throttle:public`; alias `htmx` = `HtmxOnly`,
  prepended to `SubstituteBindings` in the priority list.
- `AcceptJson` on Fortify's routes (`config/fortify.php` `middleware`) while its views
  are off, prepended to `AuthenticatesRequests` in the priority list (`routes/CLAUDE.md`).
- `Controller::render($view, $data, $fragment)` is the one htmx helper: it returns
  the named fragment only when the `HX-Target` header equals `$fragment` — a
  boosted navigation (`hx-boost` swaps a full body, no matching target) gets the
  full page.
  Head metadata travels as `App\Services\Seo\PageMeta` (`$data['meta']`),
  rendered by `x-layouts.app`; it also carries the cache tags/ttl the response
  cache stores.
<!-- endif -->
<!-- unless:htmx -->
- `AcceptJson` on Fortify's routes (`config/fortify.php` `middleware`), prepended to
  `AuthenticatesRequests` in the priority list (`routes/CLAUDE.md`).
<!-- endif -->
<!-- if:spa -->
- `statefulApi()` so Sanctum authenticates the SPA's `api` calls by the
  session cookie + XSRF token; `Route::fallback` in `routes/web.php` returns the
  SPA shell.
<!-- endif -->

## What belongs in this directory

- **`Middleware/`** — request-scoped cross-cutting concerns only. Its own
  `CLAUDE.md` carries the security-header, auth-gating and ordering rules.
- **`Controllers/`** — the abstract base `Controller.php`, shared controller
  `Concerns/`, and the domain controllers themselves (flat, since there are no
  modules). Its own `CLAUDE.md` carries the action-shape checklist.

Middleware is registered declaratively in `bootstrap/app.php` (there is no
`Http/Kernel.php`), and a missing registration fails **silently**.

Everything else — domain services, policies, jobs — belongs in its own top-level
`app/` directory. When in doubt, it does not go here.
