---
name: laravel-project-setup
description: >-
  House rules for a new Laravel project: the CLAUDE.md rule system (root + one
  per layer directory: app, Http, Controllers, Middleware, Models, Requests,
  Resources, Services, Contracts, Enums, Events, Jobs, Listeners, Policies,
  Providers, Console, Support, database, routes, tests), Stripe-style prefixed
  ids (HasPrefixedId, prefixedId()/foreignPrefixedId()), E2E-only tests (Boost's
  unit/feature guidance and testing skill overridden), headless Fortify, the
  Horizon gate, the four-seeder standard, the PHP minor and host ports,
  .claude/settings.local.json — with stack modules chosen per project (Blade +
  htmx, Svelte islands, SvelteKit SPA, Reverb) — then hands off to
  laravel-deployment and /implement-kanban. Use when starting a new Laravel
  project, bringing a project onto the house rules, or when the user types
  /laravel-project-setup. Triggers — new Laravel project, Laravel project
  setup, house rules, CLAUDE.md per layer, prefixed ids, Stripe-style ids,
  seeding standard, project scaffolding rules.
---

# Laravel project setup

`${CLAUDE_SKILL_DIR}/scripts/install.php` copies `templates/core` and the
chosen `templates/modules/<module>` into the repo, resolving
`<!-- if:MODULE -->` / `<!-- unless:MODULE -->` … `<!-- endif -->` blocks and
`{{key}}` placeholders. `templates/snippets/` are merged by hand into files the
skeleton or an installer already wrote.

**Fixed in every project:** Postgres and Redis everywhere · Octane
(FrankenPHP) in prod · Horizon · Fortify + Socialite · Pest, running E2E tests
only · Boost (dev), Claude Code the only agent · Stripe-style ids · flat layers
with a `CLAUDE.md` each · the laravel-kanban board.

**Chosen per project:**

| Module | Adds | Rule |
|--------|------|------|
| `htmx` | Blade + htmx tier, `resources/CLAUDE.md`, the Non-negotiables (speed/SEO/smoothness budgets), response cache + fragment cache + prefetch | caching exists only with htmx |
| `islands` | Svelte 5 islands inside the htmx tier, with their boot | needs `htmx` |
| `spa` | SvelteKit SPA in `frontend/`, `frontend/CLAUDE.md`, Sanctum cookie auth, same-origin serving | excludes `htmx` |
| `reverb` | Broadcasting rules (`app/Events/CLAUDE.md`), the SPA realtime spine | only when a surface really needs realtime — never "in case" |

Neither `htmx` nor `spa` → API-only backend. Any other frontend (React, Vue,
Inertia, …) has no rules yet: ask the owner for them and write that
directory's `CLAUDE.md` before any code.

## Ask, don't guess

A rule that does not fit this project, a stack piece the modules do not cover,
or an answer that leaves something undefined → ask the owner; never invent a
rule. Every decision made during setup becomes a decided card and every open
question a proposed card (step 11); until the board exists, keep both lists.
When the owner changes a rule and says it applies everywhere, change the
template here too.

## Procedure

1. **Repo.** The target is a Laravel app (`artisan` present) in git. If not,
   `composer create-project laravel/laravel <dir>` and `git init` go into the
   step 3 batch.
2. **Ask** — one AskUserQuestion: frontend (htmx / htmx + islands / SPA /
   API-only / other), Reverb (default no), the slug `{{app}}` (lowercase
   letters and digits — it names `{{app}}_test`, `config/{{app}}.php`, the
   compose project and the dev accounts `@{{app}}.test`) and the product's
   name `{{app_name}}`, the `origin` remote (a URL, or a private GitHub repo
   `gh repo create` makes in the batch), the production host name
   `{{domain}}` if known, and what we are building as free text under "Other"
   (product, users, domain rules, surfaces, what is decided vs open; options
   "Leave it open" / "In my next message"). Take what the owner knows now.
   Settle these yourself and state them in the batch:
   - **PHP minor** `{{php_version}}` — the newest this Laravel release
     supports, the same on the host, in the lock and in both images. Composer
     resolves the lock on the host, so `php -v` must show it (ask if not);
     composer.json's `php` floor is not the answer (a lock resolved on 8.5
     needs ≥ 8.4.1 for Symfony 8).
   - **Host ports** `{{web_port}}`, `{{db_port}}`, `{{redis_port}}`, and
     `{{ws_port}}` with `reverb` — free on this host (`docker ps` and every
     other compose file on the machine: a stopped stack still owns its ports)
     and outside 21000–21999 (laravel-kanban's worktree pool).
   - **LAN URL** — `http://<the host's LAN address>:{{web_port}}`, when the
     owner browses the stack from another machine.
3. **One approval batch** — a single message the owner answers once; install
   only what is approved:
   - composer: `laravel/fortify`, `laravel/socialite`, `laravel/horizon`,
     `laravel/octane`; dev `laravel/boost`, `petar-spasic/laravel-kanban` and
     `petar-spasic/laravel-house`. Pest: keep the skeleton's major; a
     PHPUnit skeleton gets the current `pestphp/pest` +
     `pestphp/pest-plugin-laravel` in place of `phpunit/phpunit`. Then
     `fortify:install`, `horizon:install` and `octane:install
     --server=frankenphp --no-interaction` (publishes `config/octane.php`;
     the FrankenPHP binary it downloads stays out of the image via
     laravel-deployment's `.dockerignore`).
   - npm, each after the maintenance check (convention 6): `htmx` →
     `htmx.org`, `htmx-ext-preload` (htmx 2 ships extensions separately),
     `typescript`; `islands` → `svelte`, `@sveltejs/vite-plugin-svelte`,
     `svelte-check`; `spa` → SvelteKit in `frontend/` with
     `@sveltejs/adapter-static`, `axios`, `zod`. No component-test tooling.
   - `spa`: `php artisan install:api` (Sanctum). `reverb`: `php artisan
     install:broadcasting` (Reverb, laravel-echo, pusher-js); its stock
     `REVERB_*` values and `BROADCAST_CONNECTION` are wrong for the container:
     `references/reverb.md` of laravel-deployment sets them (step 9).
   - `git init` and the `origin` remote, if missing.
   - Deletions: `tests/Unit`, `tests/Feature`, `database/database.sqlite`,
     `AGENTS.md` and `.agents/`, `htmx`: `resources/js/app.js`; and the files
     the templates replace wholesale — the skeleton's Boost-only `CLAUDE.md`,
     `database/seeders/DatabaseSeeder.php`, and `horizon:install`'s
     `app/Providers/HorizonServiceProvider.php`.
   - The PHP minor, the ports, the LAN URL and the decisions so far. Fortify ≥
     1.40 turns passkeys on (a vendor-owned bigint `passkeys` table, like
     `jobs`): which auth features the product keeps is open unless the owner
     decides.
4. **Install the templates** — dry run first, then for real:
   ```
   php "${CLAUDE_SKILL_DIR}/scripts/install.php" . --modules=htmx,islands --set app=acme \
     --set laravel_version=13 --set php_version=8.5 --set pest_version=5 --dry-run
   ```
   It never overwrites: the replaced files are deleted (the batch) before the
   real run; any other skipped file is read and merged by hand
   (`.claude/settings.local.json` key by key). Fill `{{what_we_are_building}}`
   with only what the owner said: a product paragraph (what, for whom,
   constraints); `### Domain rules`; `### Surfaces` — `Surface | Route group |
   Rules`, groups from `routes/CLAUDE.md`; `### Direction that is decided vs.
   still open` — "Decided (owner, <date>): …", then "Open questions are
   proposed cards on `project/decisions` (`vendor/bin/kanban list
   --board=project/decisions --stage=proposed`)." An undecided cell says
   `open`, never `_(to decide)_`, and its question joins the step 11 list.
   `{{hosting}}` is laravel-deployment's (step 9).
5. **Prefixed ids.** The installer wrote `app/Models/Concerns/HasPrefixedId.php`;
   merge `templates/snippets/AppServiceProvider-boot.php` into
   `AppServiceProvider` (with `htmx`, its `public` rate limiter too). `users` stays the skeleton's bigint.
6. **Make the rules' stated facts true** — each is a line the rules claim;
   the snippets are in `${CLAUDE_SKILL_DIR}/templates/snippets/`:
   - **Tests**: `tests/E2E/` is the only test directory; the installer wrote
     four flows (Fortify's endpoints, Horizon access, seeding, two-factor).
     `phpunit.xml` has one `E2E` testsuite on `tests/E2E` (its `<php>` block and
     `tests/bootstrap.php` are laravel-deployment's, step 9); `tests/Pest.php` is
     `pest()->extend(TestCase::class)->in('E2E');` and nothing else;
     `tests/TestCase.php` carries `#[Seeder(ReferenceDataSeeder::class)]`, and
     with `htmx` its `setUp()` calls `$this->withoutVite()`.
   - **Postgres and Redis everywhere**: the config defaults become `pgsql`
     (`config/database.php`, `config/queue.php` `batching`/`failed`) and
     `redis` (`config/queue.php`, `config/cache.php`, `config/session.php`).
     `.env` and `.env.example` drop `DB_CONNECTION`, `SESSION_DRIVER`,
     `QUEUE_CONNECTION` and `CACHE_STORE` and point at the sidecars:
     `APP_URL=http://localhost:{{web_port}}`, `DB_HOST=127.0.0.1`,
     `DB_PORT={{db_port}}`, `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD={{app}}`,
     `REDIS_PORT={{redis_port}}`. `APP_NAME={{app_name}}`: the session cookie
     is named after it, and two apps on one host under the skeleton's
     `Laravel` log each other out.
   - **Seeding**: the installer wrote the four seeders and
     `database/data/.gitkeep` (`database/CLAUDE.md`); `config/auth.php` gets
     `admins` + `operator` (`config-auth.php`); `.env` gets
     `ADMIN_EMAILS=admin@{{app}}.test`, `.env.example` documents it commented.
   - **Horizon**: the installer wrote the `viewHorizon` gate and the local
     host-only `authorization()`; `routes/console.php` gets
     `Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping();` — without it
     the dashboard's metrics stay blank.
   - **Fortify headless**: `config/fortify.php` `views => false` and
     `middleware => ['web', AcceptJson::class, 'throttle:auth-forms']`
     (`config-fortify.php`; the installer wrote `AcceptJson`); the reset-link
     URL and the `auth-forms` limiter in `FortifyServiceProvider`
     (`FortifyServiceProvider-boot.php`). `User`:
     `TwoFactorAuthenticatable`, `PasskeyAuthenticatable` + `implements
     PasskeyUser`, `two_factor_secret` and `two_factor_recovery_codes` in
     `#[Hidden]`, `two_factor_confirmed_at` cast to `datetime`.
   - `bootstrap/app.php` (`bootstrap-app.php`): no event discovery, `AcceptJson`
     ahead of `auth`; `htmx` loads routes through `then:` and defines the
     `public` group; `spa` adds `statefulApi()`.
   - `.gitignore` += `/.claude/settings.local.json` and `.env.prod` (the
     skeleton covers `.env.production` only).
   - `htmx`: `vite.config.js` `input` and `welcome.blade.php`'s `@vite` →
     `resources/js/app.ts`; `package.json` `"check": "tsc"`; append
     `htmx-indicator.css` to `resources/css/app.css`.
   - `islands`: `"check": "svelte-check --tsconfig ./tsconfig.json"` and
     `svelte()` in the Vite plugins; the installer wrote the boot
     (`islands.ts`, `island.blade.php`, `svelte.config.js`, the mount lines in
     `app.ts`).
7. **Boost** — `boost.json`: `"agents": ["claude_code"]`, `"cloud": false`,
   `"packages"` += `"petar-spasic/laravel-house"` (laravel-kanban adds itself at
   `kanban:install`); `composer.json` `post-update-cmd` ends with `@php artisan
   boost:update --ansi`, so `composer update` refreshes the guidelines and
   skills. Run `php artisan boost:install --no-interaction` yourself, never
   through `!` (Gotchas); the project's copy of the house skills is the pinned
   one, so `claude plugin disable laravel-house@laravel-house --scope project`.
   Check: root `CLAUDE.md` ends with
   the `<laravel-boost-guidelines>` block, and that block holds none of "Test
   every code change", "Unit and feature tests are more important",
   `make:test`, "Laravel Cloud", `composer run dev`; no `AGENTS.md`, no
   `.agents/`, no `.claude/skills/deploying-to-cloud`;
   `.claude/skills/testing-best-practices/SKILL.md` is the E2E one ("end to
   end or not at all"), `.claude/skills/infer-conventions/SKILL.md` the stub.
8. **Verify.** No unresolved marker or placeholder in the project's own
   files — `grep -rnE '<!-- (if|unless):|<!-- endif|\{\{[a-z_]+\}\}' .
   --exclude-dir={vendor,node_modules,.git,skills}` — except `{{hosting}}`
   (`skills` holds the house skills' own templates, which keep theirs);
   `vendor/bin/pint --dirty --format agent`; `php artisan route:list` boots;
   `htmx`: `npm run check` and `npm run build`. The E2E tests need the
   stack's `{{app}}_test` (step 9).
9. **Hand off to `laravel-deployment`** (invoke it) with `{{app}}`,
   `{{app_name}}`, `{{php_version}}`, the ports, the LAN URL, `{{domain}}` and
   the modules. It merges its `references/project-files.md` (the Vite `server`
   block, the forced phpunit `<php>` block with its `tests/bootstrap.php`, `TRUSTED_PROXIES`, the compose keys
   in `.env`) and fills `{{hosting}}` from `references/hosting-section.md`.
   The Horizon gate's host check needs the real client IP that the Vite
   proxy's `xfwd` + `TRUSTED_PROXIES` provide. Done when `php artisan test
   --compact tests/E2E` passes against the stack and the page loads from the
   LAN URL with every asset answering 200.
10. **Commit** — ask first; no Co-Authored trailer (convention 3 overrides any
    harness attribution). Main is clean afterwards: `/implement-kanban`
    requires it.
11. **`/implement-kanban`** runs only when the owner types it
    (`disable-model-invocation`): ask them to, after `/reload-skills` if this
    session started before `.claude/skills/` existed. It records the setup's
    and the deployment's decisions as decided cards and the open questions as
    proposed cards, so end your report with both lists.

## Gotchas

Symptom → cause → fix. Add a new one here in the session it is found.

- **`boost:install` run through `!` changes nothing** → bash mode has no TTY:
  the prompts never show and Boost keeps `boost.json` as it is → edit
  `boost.json`, then run `boost:install --no-interaction` yourself.
- **The skeleton's `CLAUDE.md` and `AGENTS.md` hold only Boost's bootstrap
  block** (`laravel new` with Boost also leaves `.agents/` and a `boost.json`
  naming other agents and `cloud`) → the skeleton prepares any agent to
  install Boost → the batch deletes them, the installer writes `CLAUDE.md`,
  step 7 sets `boost.json`.

## Maintaining the templates

- A template that must reach a project as `*.blade.php` is stored as
  `*.blade.php.stub`: Boost renders every `*.blade.php` inside a skill it
  copies and saves it as `.md`. Every `CLAUDE.md` template is stored as
  `CLAUDE.md.stub` too, so it does not load as instructions in this
  repository. The installer drops `.stub` on write.
- `.ai/guidelines/foundation`, `laravel/core` and `boost/core` are Boost
  2.10's with the test lines, the dev-server lines, the rules section and the
  worktree database line changed. After a Boost upgrade, diff them against
  `vendor/laravel/boost/.ai/` and carry the new upstream text over.
- The other overrides and what they counter — re-check each after an upgrade:

  | Override | Counters |
  |---|---|
  | `.ai/guidelines/enforce-tests`, `pest/core` | Boost's unit/feature test guidance |
  | `.ai/guidelines/deployments/core` (renders empty) | the Laravel Cloud pointer Boost ≥ 2.10 always adds (`GuidelineComposer::getCoreGuidelines()`) |
  | `config/boost.php` | Claude Code's guidelines going to `AGENTS.md` (`src/Install/Agents/ClaudeCode.php`) |
  | `.ai/skills/testing-best-practices` | Boost's testing skill; `.ai/skills/` is merged last, keyed by `name` |
  | `.ai/skills/infer-conventions` | Boost's convention sweep, which records into `.ai/rules` — as would any new core skill that does |
- A fix proven in a project built on these templates comes back here, with the
  project's name as `{{app}}`.
