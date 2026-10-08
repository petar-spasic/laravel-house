@houserules
@php($house = \PetarSpasic\LaravelHouse\Setup\HouseConfig::read(base_path()))
## TESTS ARE END TO END OR NOT AT ALL — overrides every other rule about tests

Unit and component tests are not written, run or relied on. Correctness is shown on the real thing: the flow run end
to end, a read-only query on the data, the output read. A test worth writing is an **E2E test** of one whole flow
through its real entry point (`tests/CLAUDE.md`); only paid or outside systems are faked. No suite is a gate before a
commit. Any rule asking for a unit test, a fail-first test, a tier choice, `composer test` or a passing suite before
a commit is void.

## ABSOLUTELY MUST FOLLOW CONVENTIONS

1. Be concise always. Verbose text is hard to follow.
2. Comment only hard logic the code cannot show. **No provenance anywhere**: comments, docs, CLAUDE.md files and
   skills state what is, never what it was or where it came from ("was X, now Y", "copied from …", "previously …").
   History lives in git and the board.
3. Never add a Co-Authored trailer to a commit.
4. YAGNI and KISS.
5. Every entity id is Stripe-style: `{prefix}_{16 base-62 chars}` (`bd_7Kq2mZp9Xt4LwRc1`). The prefix is `ID_PREFIX`
   on the model, unique across models; migrations use `$table->prefixedId()` / `foreignPrefixedId()`. Only `users`,
   vendor-owned tables and hot tables (`database/CLAUDE.md`) use bigint.
6. Dependencies: Laravel core, first-party `laravel/*` and own `petar-spasic/*` first; nothing deprecated or likely
   to be abandoned. Another package, npm included, comes in only when it clearly beats writing the code, and only after
   a maintenance check: active releases, current with the Laravel major or its toolchain, more than one maintainer
   (people committing to it in the last year, not its registry publishers). One that passes is added without asking
   and named in the commit or report. What a checked package declares (its dependencies, the files its CLI writes)
   comes with it unchecked; the packages the house prescribes are the house's to check.
7. No hosted CI (no `.github/workflows`). Pint, the type check and the E2E tests a change touches run locally before
   a push.
8. **Act; do not ask.** A change that makes the app more secure, simpler, cleaner, faster or better tested, or that
   follows a rule here, is made without asking: do it and say what you did. A technical choice between sound options
   is yours: take the safer, simpler one and say why. Ask the owner only about what the product does for its users;
   anything irreversible or outside this repository (real data, production, accounts, money, publishing); and
   loosening security.

## Where the docs live

| File | Covers |
|------|--------|
| `app/CLAUDE.md` | Backend design: flat layer layout, slim controllers and fat models, services, Octane and off-request rules |
@houserules('htmx')
| `app/Http/CLAUDE.md` | HTTP plumbing: middleware, the base controller, full page vs htmx fragment, the response cache |
@endhouserules
@unlesshouserules('htmx')
| `app/Http/CLAUDE.md` | HTTP plumbing: middleware, the base controller, JSON Resources |
@endhouserules
| `app/Models/CLAUDE.md` | Model conventions, state discipline, scopes |
| `database/CLAUDE.md` | Table structure, Postgres-only DDL; the seeding standard |
| `routes/CLAUDE.md` | Route groups and the surface each serves; the scheduler |
@unlesshouserules('spa')
| `tests/CLAUDE.md` | E2E tests: what one is, what may be faked, the auth proofs |
@endhouserules
@houserules('spa')
| `tests/CLAUDE.md` | E2E tests: what one is, what may be faked, the auth proofs; Playwright in `frontend/e2e` |
@endhouserules
@houserules('htmx')
@houserules('islands')
| `resources/CLAUDE.md` | Frontend: Blade, htmx, Svelte islands, shadcn-svelte, Tailwind |
@endhouserules
@unlesshouserules('islands')
| `resources/CLAUDE.md` | Frontend: Blade, htmx, Tailwind |
@endhouserules
@endhouserules
@houserules('spa')
| `frontend/CLAUDE.md` | The SvelteKit app: first frontend change, config, components, forms, API client, auth pages, guards, rendering, CSP |
@endhouserules
| `app/<Layer>/CLAUDE.md` | One per layer directory (`Requests`, `Resources`, `Models`, `Jobs`, `Services`, `Contracts`, `Enums`, `Events`, `Listeners`, `Policies`, `Providers`, `Console`, `Support`, `Http/Controllers`, `Http/Middleware`) |

A change to the layer layout, the process model, the auth model or what a surface exposes updates the governing file
in the **same commit**. A `CLAUDE.md` states rules, never an inventory: no list of tests, routes, policies, services,
flows, tables or scheduled commands. The code is the list (`route:list`, `schedule:list`, the directory, a grep), so a
`CLAUDE.md` changes only when a rule does.

**House rules are rendered, never edited in place.** `composer update` renders them from `config/house.php` (the app
slug, the versions, the modules): these guidelines, the part of each layer `CLAUDE.md` between `house:begin` and
`house:end`, and the house's files under `.ai/` (those setup's templates ship). An edit there is lost on the next
update. A guideline or skill of this project's own under `.ai/` is its own.
- This project's own rules go in the root `CLAUDE.md` above the Boost block, and in a layer `CLAUDE.md` after
  `house:end`. A rule that says to record or update something in a `CLAUDE.md` means that part.
- **This project's own rule outranks the house rule it contradicts**, in the same file or the root. It says so:
  "Here: … instead of the house's …". A security rule is loosened only with the owner's OK.
- A house rule that does not fit this project is overridden whole, with the owner's OK: a guideline topic by
  `.ai/guidelines/petar-spasic/laravel-house/<topic>.blade.php`, any other house file by listing its path under
  `overrides` in `config/house.php`, then editing it.
- A new Laravel, PHP or Pest version updates its value in `config/house.php` in the upgrade's commit. A module
  changes there only through a module switch (laravel-project-setup's `references/adopt.md`, Switching modules), but
  `auth-pages`: the commit that builds the last htmx auth page adds it (`resources/CLAUDE.md`, Auth pages).

## Stack

- **Laravel {{ $house->vars['laravel_version'] }}**, PHP {{ $house->vars['php_version'] }} (the host, the lock and both
  images). **Postgres** and **Redis** (queues, cache, sessions, Horizon) as the same sidecars locally and in prod.
@houserules('htmx')
@houserules('islands')
- **Blade + htmx + Svelte 5 islands**: the server renders HTML, htmx swaps fragments, Svelte owns only interactive
  leaves (`<x-island>`, shadcn-svelte). Tailwind 4 via Vite; TypeScript in `resources/js`.
@endhouserules
@unlesshouserules('islands')
- **Blade + htmx**: the server renders HTML, htmx swaps fragments. Tailwind 4 via Vite; TypeScript in
  `resources/js`.
@endhouserules
- **Not an SPA**: no client router, no Inertia, no Livewire, no Alpine (it needs `unsafe-eval`, which the CSP
  forbids).
@endhouserules
@houserules('spa')
- **SvelteKit 2 + Svelte 5** (runes, `adapter-node`, TypeScript) in `frontend/`: SSR, prerendered where the data
  exists at build time. Tailwind 4, shadcn-svelte, forms on schemas generated from the FormRequests.
- Laravel serves JSON only: no Blade pages, no `Route::fallback`, nothing of the frontend in `public/`. One origin:
  Caddy splits by path in both tiers (Frontend).
@endhouserules
- **`laravel/fortify`**, **`laravel/sanctum`**, **`laravel/socialite`**: see Auth.
- **`laravel/horizon`** runs the Redis queue workers; its dashboard is gated (`app/Providers/CLAUDE.md`).
- **`laravel/octane`** (FrankenPHP) in production: the app stays in memory between requests (`app/CLAUDE.md`).
@houserules('reverb')
- **`laravel/reverb`** for WebSockets on private channels (`app/Events/CLAUDE.md`, Broadcasting).
@endhouserules
@unlesshouserules('reverb')
- **No Reverb and no WebSocket tier.** Adding one is an owner decision and a module switch (laravel-project-setup's
  `references/adopt.md`, Switching modules): packages, stack and rules together.
@endhouserules
- **`laravel/boost`** (dev only): see Using Boost.
@unlesshouserules('spa')
- **Pest {{ $house->vars['pest_version'] }}** runs the E2E tests on the sidecar's `{{ $house->vars['app'] }}_test`
  (`phpunit.xml`). **Postgres only**: no sqlite, no driver branches.

Approved dependencies are those in `composer.json` and `package.json` today (convention 6).
@endhouserules
@houserules('spa')
- **Pest {{ $house->vars['pest_version'] }}** runs the E2E tests against the API and **Playwright** the browser flows
  (`tests/CLAUDE.md`), both on the sidecar's `{{ $house->vars['app'] }}_test`. **Postgres only**: no sqlite, no
  driver branches.

Approved dependencies are those in `composer.json` and `frontend/package.json` today; before the first frontend
change, the set in `frontend/CLAUDE.md` (convention 6).
@endhouserules

### Using Boost

- **`search-docs` before writing Laravel code.** This app is on **Laravel {{ $house->vars['laravel_version'] }}**,
  newer than most training data (no `Http/Kernel.php`, middleware and providers registered in `bootstrap/`, the
  `casts()` method).
@houserules('tenancy')
- `database-query` runs as the app role with no tenant set, so it shows no rows of a tenant-owned table. Query those
  through `pgsql_owner`, or run `select set_config('app.tenant_id', '<id>', false)` first.
@endhouserules
- **Boost is `--dev` and never ships to production**: it can query the database and run code. The prod image runs
  `composer install --no-dev`, and its MCP server stays off any public address.
@endhouserules
