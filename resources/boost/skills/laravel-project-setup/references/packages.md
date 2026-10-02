# Prescribed packages

The maintenance check (root `CLAUDE.md`, convention 6) of every package the templates prescribe. A package joins a
template's prescribed list only with a row here. Re-run every row on each pin bump and on each Laravel, SvelteKit or
Svelte major. First-party `laravel/*` and `@laravel/*` packages need no check.

- **Committers:** people with commits on the default branch in the last 12 months (the latest 100 commits; bots not
  counted).
- **Status:** `pass`, or what fails and the fallback. A failing row stays prescribed until the owner picks the
  fallback; a project never swaps it in a change.

Checked 2026-10-02.

## Composer

| Package | Prescribed by | Latest | Committers | Supports | Status |
|---|---|---|---|---|---|
| `pestphp/pest` | setup, step 3 | 5.3.0 (2026-10-01) | 14 | PHP ^8.4 | pass |
| `pestphp/pest-plugin-laravel` | setup, step 3 | 5.0.1 (2026-07-29) | 3 | Laravel ^13.23 | pass. A Laravel 12 app keeps the skeleton's Pest major |

## npm: spa (`frontend/CLAUDE.md`, step 2)

| Package | Latest | Committers | Supports | Status |
|---|---|---|---|---|
| `sv` | 1.0.1 (2026-10-01) | 11 | — | pass. The stub pins 0.17.1; a bump re-runs this table |
| `@sveltejs/kit`, `@sveltejs/adapter-node` | 3.0.0, 6.0.0 (2026-10-01) | — | Kit 3: TypeScript ^6, Vite ^8 | pass. Stay on Kit 2 (2.70.3) and adapter-node 5: `sveltekit-superforms` peers on Kit `1.x \|\| 2.x` |
| `zod` | 4.6.5 (2026-09-13) | 4 | — | pass |
| `shadcn-svelte` | 1.7.0 (2026-09-16) | 22 | Svelte 5 | pass |
| `@playwright/test` | 1.63.0 (2026-09-04) | 15 | — | pass |
| `sveltekit-superforms` | 2.31.0 (2026-09-30) | 8 | Kit 1, 2; zod 3, 4 | pass. Holds the project on Kit 2 |
| `formsnap` | 2.0.1 (2025-04-09) | 0 | superforms ^2.19 | **fails: no release or commit in 12 months.** Fallback: shadcn's `field` component, which needs no formsnap (islands use it) |
| `bits-ui` | 2.19.4 (2026-10-01) | 31 | Svelte ^5.33 | pass |
| `tailwind-variants` | 3.3.1 (2026-08-03) | 2 | — | pass |
| `clsx` | 2.1.1 (2024-04-23) | 0 | — | **fails: idle since 2024.** A finished 1 kB helper behind `cn()`; the fallback is accepting it |
| `tailwind-merge` | 3.7.0 (2026-09-12) | 1 | Tailwind 4 | **fails: one maintainer.** No alternative fits `cn()`; the fallback is accepting it |
| `tw-animate-css` | 1.4.0 (2025-09-24) | 1 | Tailwind 4 | **fails: one maintainer, 2 commits.** Fallback: its one CSS file (MIT) kept in the project |
| `@lucide/svelte` | 1.50.0 (2026-10-02) | 43 | Svelte 5 | pass |
| `svelte-sonner` | 1.2.1 (2026-08-14) | 6 | Svelte 5 | pass. Declared by shadcn's `sonner` |
| `mode-watcher` | 1.1.0 (2025-06-28) | 0 | Svelte ^5.27 | **fails: no commit in 12 months.** Declared by shadcn's `sonner`; the fallback is accepting it |
| `@internationalized/date` | 3.12.4 (2026-09-01) | 27 | — | pass. Declared by the date components and `bits-ui` |
| `pusher-js` (reverb) | 8.6.0 (2026-07-23) | 7 | — | pass |

## npm: htmx and islands (`modules.md`)

| Package | Latest | Committers | Supports | Status |
|---|---|---|---|---|
| `htmx.org` | 2.0.11 (2026-09-22) | 26 | — | pass |
| `htmx-ext-preload` | 2.1.2 (2025-10-18) | 0 | htmx 2 | **fails: no commit in 12 months.** Fallback: the layout's Speculation Rules alone |
| `typescript` | 7.0.2 (2026-07-08) | Microsoft | — | pass. Islands pin ^6: `svelte-check` peers on TypeScript ^5 or ^6 |
| `vite` | 8.3.2 (2026-10-01) | Vite team | — | pass |
| `svelte`, `@sveltejs/vite-plugin-svelte`, `svelte-check` | 5.57.1, 7.3.1, 4.7.6 | Svelte team | Vite ^8 | pass |
