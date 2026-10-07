---
name: laravel-project-setup
description: >-
  House rules for a Laravel project: the CLAUDE.md rule system (root + one per
  layer directory), Stripe-style prefixed ids, E2E-only tests (Pest through
  real routes, Playwright with spa; Boost's unit/feature guidance overridden),
  core auth in every app (Fortify, Socialite, Sanctum), the Horizon gate, the
  four-seeder standard, the PHP minor and host ports, plus modules chosen per
  project: Blade + htmx, Svelte islands, a SvelteKit SSR app (adapter-node) in
  frontend/, Reverb, row-level tenancy. Frontend, Socialite and tenancy ship
  as binding rules, not code. Also brings an existing house project onto the
  current core. Hands off to laravel-deployment and /implement-kanban. Use
  when starting a Laravel project, bringing one onto the house rules, or when
  the user types /laravel-project-setup. Triggers — new Laravel project,
  Laravel project setup, house rules, CLAUDE.md per layer, prefixed ids,
  Stripe-style ids, seeding standard, multi-tenancy, Socialite, Sanctum, adopt
  house core, validation:export.
---

# Laravel project setup

This skill starts a Laravel project on the house rules. It also brings an existing house project onto the current
core (Adopting the current core).

`${CLAUDE_SKILL_DIR}/scripts/install.php` copies `templates/core` and each chosen `templates/modules/<module>` into the
repo. It resolves `<!-- if:m -->` / `<!-- unless:m -->` … `<!-- endif -->` blocks and `{{key}}` placeholders. The
files in `templates/snippets/` are merged by hand into files that already exist. laravel-deployment renders its own
templates with the same script (`--templates`), with `# if:m` … `# endif` blocks in files that are not Markdown.

The house ships rules, not frontend code. Frontends, social sign-in and tenancy ship as binding rules. The project
builds each piece when its work needs it.

Every `composer update` renders the house rules again from `config/house.php` (the modules and values): Boost renders
the root `CLAUDE.md`'s as package guidelines (`references/boost.md`), and `house:update` (`install.php --update`) the
span between `house:begin` and `house:end` in each layer `CLAUDE.md`, and the `.ai/` files.

## What every project gets

- Postgres and Redis in every tier, Octane on FrankenPHP in production, and Horizon.
- Core auth: Fortify, Socialite and Sanctum. The API lives under `/api/v1` behind `auth:sanctum`. Only `spa` lets the
  session cookie authenticate the API; every other module's API takes tokens only.
- Pest, running end-to-end tests only.
- Boost (dev), with Claude Code as the only agent.
- Stripe-style prefixed ids.
- Flat layers, each with its own `CLAUDE.md`; the house rules rendered on every `composer update`.
- The seeding standard: four seeders (`database/CLAUDE.md`).
- The kanban board ships with the house; the owner chooses whether to adopt it (`/implement-kanban`).

## Modules

This table owns the combination rules. `install.php` enforces them and refuses any other combination.

| Module | Adds | Rule |
|---|---|---|
| `htmx` | the Blade + htmx tier: `resources/CLAUDE.md` and the htmx boot files | — |
| `islands` | the Svelte 5 islands boot inside the htmx tier | needs `htmx` |
| `auth-pages` | the rules for Fortify's views on; a state, never chosen at setup | needs `htmx`; the commit that builds the last auth page adds it |
| `spa` | `frontend/CLAUDE.md` only; the project creates the SvelteKit app in `frontend/` | excludes `htmx` and `islands` |
| `reverb` | broadcasting rules (`app/Events/CLAUDE.md`) | only when a surface needs realtime, never "in case" |
| `tenancy` | row-level tenancy rules in the core stubs | with any frontend, API-only included |

- Neither `htmx` nor `spa` means an API-only backend.
- Any other frontend (React, Vue, Inertia, …) has no rules yet. Ask the owner for them, and write that directory's
  `CLAUDE.md` before any code.
- What each module installs, deletes, wires and checks: `references/modules.md`.

## Ask, don't guess

- A rule that does not fit this project, a stack piece no module covers, or an answer that leaves something undefined:
  ask the owner. Never invent a rule.
- Keep two lists: every decision made during setup, and every open question. The decisions become rules in the
  `CLAUDE.md` files; with the board, `/implement-kanban` puts the open questions on cards (step 11).
- When the owner changes a rule and says it applies everywhere, change the template here too.

## Procedure

1. **Repo.** The target is this directory: a Laravel app (`artisan` present) in git. If it is not, the step 3 batch
   creates the app here. `composer create-project` refuses a non-empty directory, and this one may hold `.claude/`. So
   the batch runs `tmp=$(mktemp -d)`, then `composer create-project laravel/laravel "$tmp"`, then `cp -a "$tmp"/. .`,
   then `rm -rf "$tmp"` and `git init`.
2. **Ask.** One AskUserQuestion covers:
   - the frontend: htmx, htmx + islands, spa, API-only, or other;
   - Reverb (default no) and tenancy (default no);
   - the slug `{{app}}`: lowercase letters and digits. It names `{{app}}_test`, `config/{{app}}.php`, the compose
     project and the dev accounts `@{{app}}.test`;
   - the product name `{{app_name}}`;
   - the `origin` remote: a URL, or a private GitHub repo that `gh repo create` makes in the batch;
   - the production host name `{{domain}}`, if known;
   - what we are building, as free text under "Other": product, users, domain rules, surfaces, what is decided and
     what is open. Offer "Leave it open" and "In my next message".

   Settle these yourself and state them in the batch:
   - **PHP minor** `{{php_version}}`: the newest this Laravel release supports. It is the same on the host, in the
     lock and in both images. Composer resolves the lock on the host, so `php -v` must show it; ask if it does not.
     Never take it from composer.json's `php` floor: the lock's dependencies may need more.
   - **Host ports** `{{web_port}}`, `{{db_port}}`, `{{redis_port}}`, plus `{{ws_port}}` with `reverb` but not `spa`:
     `php "${CLAUDE_SKILL_DIR}/scripts/ports.php" --modules=…` prints them as `--set` arguments. It skips listening
     ports, every container's ports and the kanban worktree pool. Add `--avoid=…` for another project's compose
     ports whose stack is removed.
   - **LAN URL**: `http://<the host's LAN address>:{{web_port}}`, when the owner browses the stack from another
     machine.
3. **One approval batch.** A single message the owner answers once. Install only what is approved.
   - Composer: `laravel/fortify`, `laravel/socialite`, `laravel/horizon`, `laravel/octane`; dev `laravel/boost`,
     `petar-spasic/laravel-house`.
   - Pest: keep the skeleton's major. A PHPUnit skeleton gets the current `pestphp/pest` and
     `pestphp/pest-plugin-laravel` in place of `phpunit/phpunit`.
   - Artisan: `install:api --without-migration-prompt`, `fortify:install`, `horizon:install`,
     `octane:install --server=frankenphp --no-interaction`. The stack's entrypoint migrates; a bare
     `--no-interaction` on `install:api` would migrate whatever database the host `.env` names.
   - The module packages, commands and deletions in `references/modules.md`. Each npm package passes the maintenance
     check first (convention 6; the prescribed ones are `references/packages.md`). No component-test tooling.
   - `git init` and the `origin` remote, if missing. The board page syncs only when `origin` is an ssh URL
     (`git@host:owner/repo.git`). `gh repo create` uses https unless `gh config get git_protocol` says ssh. So run
     `git remote get-url origin` afterwards. If it is https, run
     `git remote set-url origin git@github.com:<owner>/<repo>.git`.
   - Step 4's `--fresh` deletes `tests/Unit`, `tests/Feature`, `AGENTS.md`, `.agents/` and the files the templates
     replace, and makes the other fixed edits to the skeleton.
   - The PHP minor, the ports, the LAN URL and the decisions so far. Passkeys are on in every project
     (`references/core-auth.md`). Which other auth features the product keeps stays open unless the owner decides.
4. **Install the templates.** Run a dry run first, then the real run:

   ```shell
   php "${CLAUDE_SKILL_DIR}/scripts/install.php" . --modules=htmx,islands,tenancy --set app=acme \
     --set app_name="Acme Notes" --set web_port=8000 --set db_port=5433 --set redis_port=6380 \
     --set laravel_version=13 --set php_version=8.5 --set pest_version=5 --fresh --dry-run
   ```

   - `--fresh` runs only on a repo with no commit yet. It deletes what the templates replace or the modules drop,
     points `.env`, `.env.example` and the config defaults at Postgres and Redis, writes `boost.json`, and wires
     composer, npm and `.gitignore` (`references/project-wiring.md`, `references/modules.md`). Fix each
     `merge by hand:` line it prints. An existing project goes through `references/adopt.md` instead.
   - It never overwrites a file; read each skipped one and merge it by hand (`.claude/settings.local.json` key by key).
     It records the modules and the house values in `config/house.php`.
   - Then render the snippets outside the repo with the same `--modules` and `--set`s plus
     `--render-to="$(mktemp -d)"`. It writes nothing into the repo, and its first output line names `<dir>`.
   - Steps 5 and 6 merge from `<dir>/snippets/`: its module blocks are resolved and `{{app}}` is filled. Never copy a
     raw snippet. Keep `<dir>` until step 6 is done.
   - Fill `{{what_we_are_building}}` with only what the owner said: a product paragraph (what, for whom,
     constraints); `### Domain rules`; `### Surfaces` (`Surface | Route group | Rules`, groups from
     `routes/CLAUDE.md`); `### Direction that is decided vs. still open` ("Decided (owner, <date>): …", "Open: …").
   - An undecided cell says `open`, never `_(to decide)_`, and its question joins the step 11 list.
   - `{{hosting}}` belongs to laravel-deployment (step 9).
5. **Prefixed ids.** The installer wrote `app/Models/Concerns/HasPrefixedId.php`. Merge the rendered
   `AppServiceProvider-boot.php` into `AppServiceProvider`. `users` keeps the skeleton's bigint id.
6. **Make the rules' stated facts true.** The rules state facts about the project; make each one hold:
   - core auth (Sanctum, `bootstrap/app.php`, Fortify, the `User` model): `references/core-auth.md`;
   - tests, Postgres and Redis, seeding, Horizon, `.gitignore`: `references/project-wiring.md`;
   - each module's wiring: `references/modules.md`.

   Then delete `<dir>`.
7. **Boost.** `--fresh` wrote `boost.json` and composer's `post-update-cmd` (`house:update`, then `boost:update`). Run
   `php artisan boost:install --no-interaction` yourself, never through `!` (Gotchas). Disable the plugin for the
   project. Detail: `references/boost.md`.
8. **Verify.** `php "${CLAUDE_SKILL_DIR}/scripts/verify.php" .` prints nothing: no marker or placeholder but
   `{{hosting}}`, the deletions, `.env`, `.gitignore`, the house files in sync, Boost with the house guidelines and its
   overrides, one E2E suite, and `route:list` boots. Then:
   - `vendor/bin/pint --dirty --format agent`;
   - the module checks in `references/modules.md`;
   - the E2E tests need the stack's `{{app}}_test` (step 9).
9. **Hand off to `laravel-deployment`.** Invoke it with `{{app}}`, `{{app_name}}`, `{{php_version}}`, the ports, the
   LAN URL, `{{domain}}` and the modules, tenancy included. It renders its templates, merges its snippets and fills
   `{{hosting}}`. Done when `php artisan test --compact tests/E2E` passes against the stack, and:
   - htmx: the page loads from the LAN URL with every asset answering 200;
   - API-only and spa: through Caddy, `/up` answers 200 and `/api/v1/x` answers Laravel's JSON 404, never a 502.
     With spa, the Node checks wait for the first frontend change (`frontend/CLAUDE.md`).
10. **Commit.** Ask first. No Co-Authored trailer (root `CLAUDE.md`, convention 3, overrides any harness attribution).
    Main is clean afterwards, because `/implement-kanban` requires it. With an empty `origin`, push `main` in the same
    approval. Otherwise the board branch is pushed first and becomes the default branch.
11. **The board, if the owner wants it.** `/implement-kanban` runs only when the owner types it
    (`disable-model-invocation`), after `/reload-skills` if this session started before `.claude/skills/` existed. It
    puts the open questions on cards; without the board they stay in the root `CLAUDE.md`. End your report with both
    lists. The open list always has:
    - which social providers are on;
    - email verification on or off;
    - htmx: "Build the auth pages" (`resources/CLAUDE.md`, Auth pages);
    - spa: where the prerendered pages' data comes from (`frontend/CLAUDE.md`, Rendering, prerendering, caching).

## Adopting the current core

Use this for a house project set up on an earlier core.

1. Check that the project's `.claude/skills/laravel-project-setup/references/adopt.md` exists. If it does not, the
   package is old. Run `composer require --dev petar-spasic/laravel-house` with no constraint, then
   `php artisan boost:update`, then restart Claude Code.
2. Follow `references/adopt.md` (no `config/house.php` yet: its Rendered rules first; another module set: Switching
   modules).

Adopting never adds tenancy. Tenancy on an app with data is an owner decision and a data migration.

## Gotchas

Each is symptom → cause → fix. Add a new one here in the session it is found.

- **`boost:install` run through `!` changes nothing** → bash mode has no TTY, so the prompts never show and Boost
  keeps `boost.json` as it is → edit `boost.json`, then run `boost:install --no-interaction` yourself.
- **`boost:install --no-interaction` installs guidelines and MCP but no skills** → without prompts Boost installs only
  the features `boost.json` turns on (`guidelines`, `mcp`, a non-empty `skills`), and all three only when none is →
  write `boost.json` with only `references/boost.md`'s three keys, then rerun.
- **The skeleton's `CLAUDE.md`, `AGENTS.md`, `.agents/` and `boost.json` target other agents** → `laravel new` with
  Boost prepares every agent → the batch deletes them, the installer writes `CLAUDE.md`, and step 7 sets `boost.json`.
- **`route:list --path=api/v1` lists nothing after `install:api`** → `bootstrap/app.php` loads routes through `then:`
  with no `web:` line, so `install:api` only warned → add `api: __DIR__.'/../routes/api.php'` and
  `apiPrefix: 'api/v1'` by hand.

## Maintaining the templates

- A template that must reach a project as `*.blade.php` is stored as `*.blade.php.stub`. Boost renders every
  `*.blade.php` inside a skill it copies and saves it as `.md`.
- A `CLAUDE.md` template is a `CLAUDE.md.stub` (it would load here), wrapped in `house:begin` and `house:end` but
  the root one. The root rules are `resources/boost/guidelines/`, `@houserules('<module>')` for `<!-- if:<module> -->`.
- A block marker sits alone on its line. Blocks may nest. Each `if:` or `unless:` names a module in
  `scripts/HouseConfig.php`'s `MODULES`.
- Module text stays inside its marker. A line naming SvelteKit, adapter-node, `/api/auth` or `statefulApi` sits in
  `<!-- if:spa -->`, with an `<!-- unless:spa -->` sibling where other modules need their own line. Any tenant word
  sits in `<!-- if:tenancy -->`. The repo's installer matrix greps for leaks.
- A new placeholder joins step 4's `--set` list and the repo's installer matrix.
- Boost's overrides and how to re-check them after a Boost upgrade: `references/boost.md`.
- A fix proven in a project built on these templates comes back here, with the project's name as `{{app}}`.
