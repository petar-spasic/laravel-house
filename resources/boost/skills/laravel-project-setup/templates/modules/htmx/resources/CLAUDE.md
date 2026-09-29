# CLAUDE.md — resources (frontend)

<!-- if:islands -->
Scope: `resources/`. Backend design is `app/CLAUDE.md`; the CSP/escaping/CSRF
rules are summarised in the root `CLAUDE.md`. This file is how the three
frontend layers — Blade, htmx, Svelte islands — divide the work.
<!-- endif -->
<!-- unless:islands -->
Scope: `resources/`. Backend design is `app/CLAUDE.md`; the CSP/escaping/CSRF
rules are summarised in the root `CLAUDE.md`. This file is how the two
frontend layers — Blade and htmx — divide the work.
<!-- endif -->

## Layout

```
resources/
├── css/app.css                  # Tailwind 4 entry (+ htmx indicator rules)
├── js/
<!-- if:islands -->
│   ├── app.ts                   # htmx config (CSP, CSRF, 422) + islands boot
│   ├── islands.ts               # the registry: data-island → Svelte component
│   ├── islands/<Name>.svelte    # one file per island; filename = island name
<!-- endif -->
<!-- unless:islands -->
│   ├── app.ts                   # htmx config (CSP, CSRF, 422)
<!-- endif -->
│   ├── htmx-global.ts           # window.htmx, set before the extensions import
│   └── global.d.ts              # window.htmx typing
└── views/
    ├── components/layouts/app.blade.php   # <x-layouts.app :meta> (PageMeta)
<!-- if:islands -->
    ├── components/island.blade.php        # <x-island name :props>
<!-- endif -->
    ├── components/…                       # anonymous Blade components
    ├── partials/…                         # fragments shared by full page + htmx
    └── <feature>/…                        # pages, one directory per feature
```

The Vite dev server runs inside the local container (root `CLAUDE.md`,
Hosting); `npm run build` for prod;
<!-- if:islands -->
`npm run check` (svelte-check + tsc) is the frontend type gate and
must pass before finishing any change under `resources/js`.
<!-- endif -->
<!-- unless:islands -->
`npm run check` (tsc) is the frontend type gate and must pass before
finishing any change under `resources/js`.
<!-- endif -->

## Speed, SEO, smoothness — the rules this directory owns

Root `CLAUDE.md` "Non-negotiables" sets the budgets; these are the mechanics.

- **Complete without JS.** Every public page renders its full content
  server-side. Real `<a href>` for every navigation (htmx boosts them; a
  crawler follows them). Nothing a crawler or a no-JS user needs sits behind
  `hx-get`, an island, or a click. Tabs/accordions on public pages are CSS
  (`<details>`, `:target`) or server-rendered, not JS-toggled hidden content.
- **Head is per page, from the controller**: `<title>`, `meta description`,
  `<link rel="canonical">`, Open Graph, JSON-LD (rendered by a Blade component
  from a Resource-shaped array, under a CSP nonce), `hreflang` where a page
  exists in more than one script/locale. The layout exposes named slots for
  them; no public page leaves them empty.
- **Prefetch by default on public pages.** The layout enables `hx-boost` +
  the htmx `preload` extension (`preload="mouseover"`, touchstart on mobile)
  on the main nav and listing links, and emits a `speculationrules` block:
  `prefetch` for links in the viewport with moderate eagerness, `prerender`
  for the one most likely next page (chosen by the controller, e.g. the first
  result). Never prefetch anything authenticated, anything with side effects,
  or anything not served by the response cache — a prefetch that hits PHP is a
  DoS on ourselves.
- **Zero layout shift.** Every `<img>` has `width`/`height` (or
  `aspect-ratio`), `loading="lazy"` below the fold, `srcset` + modern
  formats; the LCP image is eager with `fetchpriority="high"`. Placeholders
  (island slots, htmx targets, skeletons) are the same size as what replaces
  them. Fonts are self-hosted (`@font-face` in `app.css`, woff2 only from `resources/fonts/`), `font-display: swap`, subset to the
  scripts we serve.
- **Smooth swaps.** No View Transitions (`globalViewTransitions = false`, never
  `transition:true` — cross-fades read as a flash);
  `hx-indicator` only for requests expected to
  exceed 300 ms; `hx-sync` on filters so typing does not queue requests;
  `hx-push-url` on boosted navigation so back/forward and sharing work;
  `hx-replace-url` for in-page state (filters, sort, view) so the URL stays
  shareable but Back leaves the page instead of undoing one chip.
<!-- if:islands -->
- **JS budget.** A public page ships `app.ts` and nothing else unless an
  island is on it; islands are lazy chunks loaded on first mount and mount
  into a same-sized placeholder **after** the page is interactive. Measure:
  `npm run build` prints chunk sizes — a public-page island over ~15 KB
  gzipped needs a reason in the PR.
- **htmx + Blade first.** Before writing an island, write the htmx version in
  your head: if it is a couple of `hx-*` attributes and a fragment route, do
  that. An island is justified only when local state must change many times
  before (or without) a round trip — drag-and-drop, live editing, a picker
  with client-side filtering of an already-loaded set. "JS would be nicer" is
  not a justification.
<!-- endif -->
<!-- unless:islands -->
- **JS budget.** A public page ships `app.ts` and nothing else.
<!-- endif -->

## Who does what

| Need | Layer | Not |
|------|-------|-----|
<!-- if:islands -->
| Render a page, a list, a form | **Blade** | an island that fetches JSON |
| Submit, paginate, filter, reload part of a page | **htmx** (`hx-get`/`hx-post` → server renders the fragment) | `fetch()` in an island |
| Rich local state before/without a round trip (drag-drop, editors, pickers, live previews) | **Svelte island** | Blade + a pile of `hx-*` |
| Persist what an island did | a normal form submit / `htmx.ajax()` with the island's state in inputs | an island-owned JSON API |
| Shared state between two islands on one page | a `resources/js/*.svelte.ts` module with runes | `window.*`, DOM attributes |

If an island genuinely needs JSON from the server, the route lives in the
`api` group and returns a Resource (`app/Resources/CLAUDE.md`) — decided per
case, not by default.
<!-- endif -->
<!-- unless:islands -->
| Render a page, a list, a form | **Blade** | JSON rendered by client code |
| Submit, paginate, filter, reload part of a page | **htmx** (`hx-get`/`hx-post` → server renders the fragment) | `fetch()` |
<!-- endif -->

## Blade

- Pages wrap in `<x-layouts.app :meta="$meta">` (`App\Services\Seo\PageMeta`
  built by the controller; `:title` alone only for non-public pages). It owns
  `<head>` — title, description, canonical, robots, prev/next, OG, JSON-LD
  (`partials/seo/jsonld`, nonce), the speculation-rules block
  (`PageMeta::speculationRules()`), the CSRF meta tag (only when a session is
  started) and `@vite`. No page includes assets itself. `hx-boost` is set
  per navigation block, never on `<body>` (see Prefetch above).
- **Full page and fragment are the same markup.** Either `@include` a partial
  from both the page and the fragment route, or wrap the section in
  `@fragment('name')` and return `view(…)->fragment('name')` for htmx
  callers. Never a second copy. Fragment ids are the read-path contract —
  stable names, one per page.
- Anonymous components (`resources/views/components/*.blade.php`) for
  presentational reuse; a class-based component only when it needs PHP. A leaf
  rendered per row in a loop (money, icon, image, chip, date) is a plain
  partial, not an `<x-*>`: the attribute-bag and slot machinery costs ~0.1 ms
  per render, most of a listing's render time at 24 cards × 10 leaves.
<!-- if:islands -->
- `{{ }}` always; `{!! !!}` only for `Markdown::render()` output and the
  layout's inlined stylesheet (`Vite::content`, a compiled asset). Never
  `@json($model)` — if the client needs data it goes through `<x-island :props>`
  or a Resource.
<!-- endif -->
<!-- unless:islands -->
- `{{ }}` always; `{!! !!}` only for `Markdown::render()` output and the
  layout's inlined stylesheet (`Vite::content`, a compiled asset). Never
  `@json($model)` — if the client needs data it goes through a Resource.
<!-- endif -->
- Enum display is `$model->status->label()` (`app/Enums/CLAUDE.md`), never a
  `@switch` on `->value` in the template.
- Named routes everywhere: `route()` in `href`, `action`, and every `hx-*`
  URL.
- Blade gotcha: never put text directly after a closing slot tag on the same
  line (`</x-slot:header>Body` compiles to `@endslotBody`, an unknown
  directive that leaks into the page). Break the line.
- Session state on publicly cached pages: cached pages ship stateless markup
  and hydrate without a request — a plain cookie mirrors what the page needs
  to show (a count, a badge) and `app.ts` reads it on load and after every
  swap. `@csrf` renders only when a session is started — never into shared
  cached HTML.
- Overlays are native HTML — `<dialog>`, the Popover API, `<details>` — opened
  and closed by delegated listeners in `app.ts`; tabs are real links. Toasts
  come from an `HX-Trigger` header the layout's listener renders.
- Structured data (JSON-LD, breadcrumbs included) is rendered once, by the
  layout, under the nonce — a component never emits a second copy.
- Icons come from one sprite the layout renders once and views `<use>`; never
  inline SVG paths in a view. Decorative by default; a standalone icon gets a
  label.
- Form controls wire `aria-invalid` and `aria-describedby` to their hint and
  error ids; errors come from `$errors` (the 422 fragment).

## htmx

Configured once in `app.ts` — do not repeat any of this per element:

- `allowEval = false` (CSP) → **no `hx-on:*`, no `hx-vals='js:…'`.** Behaviour
<!-- if:islands -->
  that needs JS is an island or a listener in `app.ts`.
<!-- endif -->
<!-- unless:islands -->
  that needs JS is a listener in `app.ts`.
<!-- endif -->
- `selfRequestsOnly = true` → htmx only talks to this origin.
- CSRF header added in `htmx:configRequest`; **no per-form `hx-headers`**.
- 422 responses are swapped (`htmx:beforeSwap`): a failed FormRequest
  re-renders the form fragment with `$errors`, status 422.
- `includeIndicatorStyles = false`: `.htmx-indicator` rules live in `app.css`.
- After a successful htmx POST, redirect with the `HX-Redirect` header (never
  302 — htmx follows it and swaps the whole page into the target).
- Flash messages for htmx callers come back **in the fragment** (or a toast
  via `HX-Trigger`), not via `back()->with()`.
- Fragment endpoints are ordinary routes in the page's group with the same
  auth, policy and validation (`routes/CLAUDE.md`).

<!-- if:islands -->
## Svelte islands

- **One file per island** in `resources/js/islands/`, PascalCase, TypeScript
  (`<script lang="ts">`), Svelte 5 runes (`$props`, `$state`, `$derived`) —
  no legacy `export let` / stores-as-state.
- Registered automatically by filename (`islands.ts` globs the directory,
  **lazily** — an island's code downloads on first mount, so unused islands
  cost a public page nothing).
  Mounted from Blade:

  ```blade
  <x-island name="TaskBoard" :props="TaskBoardResource::make($board)->resolve()">
      <span class="text-sm text-muted">Loading…</span>   {{-- placeholder --}}
  </x-island>
  ```

  `:props` must be JSON-safe data from a Resource (or a plain array of
  scalars). **Never a model** — the Resource is the leak boundary, and Blade's
  `{{ }}` escaping on the attribute is what keeps user text from breaking
  out.
- Declare props with a type: `let { start = 0 }: { start?: number } = $props();`
  Everything the island needs arrives as props; it never reads the DOM outside
  its own element or `window` globals.
- **Lifecycle is handled for you.** `app.ts` mounts islands on page load and on
  every `htmx:load` (fragment swapped in), and unmounts on
  `htmx:beforeCleanupElement`. An island inside a swapped fragment remounts
  with the fragment's fresh props — do not keep client-only state that must
  survive a swap; that is the server's state.
- Tailwind classes work inside islands (no shadow DOM). Styling in `<style>`
  blocks only for what utilities cannot express.
- `{@html}` follows the `{!! !!}` rule: `Markdown::render()` output only.
- No fetching inside islands by default (see "Who does what"). No global
  event bus; shared state is an explicit `*.svelte.ts` module.
- `npm run check` must be clean. Suppress a Svelte warning only with a
  `svelte-ignore` comment that says why.

<!-- endif -->
## Tailwind

- Tailwind 4, config-less: the design's tokens — colour, type, spacing, grid —
  live only in `@theme` in `app.css`. No `tailwind.config.js`. Two checklist rules: **semantic colour means
  something** (one token per meaning — never decoration, never colour alone)
  and **no spinner under 300 ms** (`hx-indicator` only on requests that can
  genuinely be slow).
- A view is components, partials and utilities over those tokens: no
  hex, font family or one-off spacing in a view, no page-specific stylesheet.
  A pattern needed twice becomes a component first.
- Money, numbers and time are formatted in exactly one place each (a partial
  or a `Support` formatter). Never concatenate a price, a number or a date in
  Blade.
- Blade gotcha: a directive glued to a word (`word@if`) is not compiled, and a
  directive glued to a component's closing tag (`/>@endif`) breaks the
  compile. Leave a space; put the branch's own space inside the branch.
- Breakpoint gotcha: Tailwind emits arbitrary `min-[…]:` variants *before* the
  named ones, so `xl:w-64 min-[1440px]:w-80` resolves to `w-64`. Use a base
  class plus the arbitrary override, never both variants on one property.
<!-- if:islands -->
- Blade and `.svelte` files under `resources/` are scanned automatically;
  vendor Blade that needs scanning is listed with `@source` in `app.css`.
<!-- endif -->
<!-- unless:islands -->
- Blade files under `resources/` are scanned automatically; vendor Blade that
  needs scanning is listed with `@source` in `app.css`.
<!-- endif -->

## Tests

A page or fragment flow is an E2E test through `$this->get(route(…))`, with
`HX-Request` for a fragment (`tests/CLAUDE.md`).
