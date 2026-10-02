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

The house ships rules, not frontend code. Frontends, social sign-in and tenancy ship as binding rules in the generated
`CLAUDE.md` files. The project builds each piece when its work needs it.

## What every project gets

- Postgres and Redis in every tier, Octane on FrankenPHP in production, and Horizon.
- Core auth: Fortify, Socialite and Sanctum. The API lives under `/api/v1` behind `auth:sanctum`. Only `spa` lets the
  session cookie authenticate the API; every other module's API takes tokens only.
- Pest, running end-to-end tests only.
- Boost (dev), with Claude Code as the only agent.
- Stripe-style prefixed ids.
- Flat layers, each with its own `CLAUDE.md`.
- The seeding standard: four seeders (`database/CLAUDE.md`).
- The kanban board ships with the house; the owner chooses whether to adopt it (`/implement-kanban`).

## Modules

This table owns the combination rules. `install.php` enforces them and refuses any other combination.

| Module | Adds | Rule |
|---|---|---|
| `htmx` | the Blade + htmx tier: `resources/CLAUDE.md` and the htmx boot files | — |
| `islands` | the Svelte 5 islands boot inside the htmx tier | needs `htmx` |
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
- Keep two lists: every decision made during setup, and every open question. With the board, `/implement-kanban`
  turns them into decided and proposed cards (step 11).
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
   - **Host ports** `{{web_port}}`, `{{db_port}}`, `{{redis_port}}`, plus `{{ws_port}}` with `reverb` but not `spa`
     (there Reverb shares the web port at `/app/*`). Each is free on this host: check `docker ps -a`, since a stopped
     container still owns its ports, and the other compose files on the machine. Each is outside 21000–21999
     (the kanban worktree pool). With `spa`, the web port is never 8080 (laravel-deployment `references/spa.md`).
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
   - `octane:install` downloads a FrankenPHP binary. `git check-ignore frankenphp public/frankenphp-worker.php` must
     print both paths; add any it leaves out to `.gitignore`.
   - The module packages, commands and deletions in `references/modules.md`. Each npm package passes the maintenance
     check first (convention 6; the prescribed ones are `references/packages.md`). No component-test tooling.
   - `git init` and the `origin` remote, if missing. The board page syncs only when `origin` is an ssh URL
     (`git@host:owner/repo.git`). `gh repo create` uses https unless `gh config get git_protocol` says ssh. So run
     `git remote get-url origin` afterwards. If it is https, run
     `git remote set-url origin git@github.com:<owner>/<repo>.git`.
   - Deletions in every project: `tests/Unit`, `tests/Feature`, `database/database.sqlite`, `AGENTS.md`, `.agents/`.
     Also the files the templates replace whole: the skeleton's `CLAUDE.md`, `database/seeders/DatabaseSeeder.php`
     and `horizon:install`'s `app/Providers/HorizonServiceProvider.php`.
   - The PHP minor, the ports, the LAN URL and the decisions so far. Passkeys are on in every project
     (`references/core-auth.md`). Which other auth features the product keeps stays open unless the owner decides.
4. **Install the templates.** Run a dry run first, then the real run:

   ```shell
   php "${CLAUDE_SKILL_DIR}/scripts/install.php" . --modules=htmx,islands,tenancy --set app=acme \
     --set laravel_version=13 --set php_version=8.5 --set pest_version=5 --dry-run
   ```

   - It never overwrites, so step 3's deletions must already be done.
   - Read every other skipped file and merge it by hand. Merge `.claude/settings.local.json` key by key.
   - Then render the snippets outside the repo with the same `--modules` and `--set`s plus
     `--render-to="$(mktemp -d)"`. It writes nothing into the repo, and its first output line names `<dir>`.
   - Steps 5 and 6 merge from `<dir>/snippets/`: its module blocks are resolved and `{{app}}` is filled. Never copy a
     raw snippet. Keep `<dir>` until step 6 is done.
   - Fill `{{what_we_are_building}}` with only what the owner said:
     - a product paragraph: what, for whom, constraints;
     - `### Domain rules`;
     - `### Surfaces`: `Surface | Route group | Rules`, with the groups from `routes/CLAUDE.md`;
     - `### Direction that is decided vs. still open`: "Decided (owner, <date>): …", then "Open: …".
   - An undecided cell says `open`, never `_(to decide)_`, and its question joins the step 11 list.
   - `{{hosting}}` belongs to laravel-deployment (step 9).
5. **Prefixed ids.** The installer wrote `app/Models/Concerns/HasPrefixedId.php`. Merge the rendered
   `AppServiceProvider-boot.php` into `AppServiceProvider`. `users` keeps the skeleton's bigint id.
6. **Make the rules' stated facts true.** The rules state facts about the project; make each one hold:
   - core auth (Sanctum, `bootstrap/app.php`, Fortify, the `User` model): `references/core-auth.md`;
   - tests, Postgres and Redis, seeding, Horizon, `.gitignore`: `references/project-wiring.md`;
   - each module's wiring: `references/modules.md`.

   Then delete `<dir>`.
7. **Boost.** Set `boost.json` and composer's `post-update-cmd`. Run `php artisan boost:install --no-interaction`
   yourself, never through `!` (Gotchas). Disable the plugin for the project. Detail and the checks:
   `references/boost.md`.
8. **Verify.**
   - No unresolved marker or placeholder in the project's own files, except `{{hosting}}`:
     `grep -rnE '<!-- (if|unless):|<!-- endif|\{\{[a-z_]+\}\}' . --exclude-dir={vendor,node_modules,.git,skills}`.
     `skills` holds the house skills' own templates, which keep theirs.
   - `vendor/bin/pint --dirty --format agent`.
   - `php artisan route:list` boots.
   - The module checks in `references/modules.md`.
   - The E2E tests need the stack's `{{app}}_test` (step 9).
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
    records the decisions as decided cards and the open questions as proposed cards; without the board they stay in
    the root `CLAUDE.md`. End your report with both lists. The open list always has:
    - which social providers are on;
    - email verification on or off;
    - htmx: "Build the auth pages" (`resources/CLAUDE.md`, Auth pages);
    - spa: where the prerendered pages' data comes from (`frontend/CLAUDE.md`, Rendering, prerendering, caching).

## Adopting the current core

Use this for a house project set up on an earlier core.

1. Check that the project's `.claude/skills/laravel-project-setup/references/adopt.md` exists. If it does not, the
   package is old. Run `composer require --dev petar-spasic/laravel-house` with no constraint, then
   `php artisan boost:update`, then restart Claude Code.
2. Follow `references/adopt.md`; moving to another module set is its Switching modules.

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
- Every `CLAUDE.md` template is stored as `CLAUDE.md.stub`, so it does not load as instructions in this repository.
  The installer drops `.stub` on write.
- A block marker sits alone on its line. Blocks may nest. Each `if:` or `unless:` names a module in `install.php`'s
  `MODULES`.
- Module text stays inside its marker. A line naming SvelteKit, adapter-node, `/api/auth` or `statefulApi` sits in
  `<!-- if:spa -->`, with an `<!-- unless:spa -->` sibling where other modules need their own line. Any tenant word
  sits in `<!-- if:tenancy -->`. The repo's installer matrix greps for leaks.
- A new placeholder joins step 4's `--set` list and the repo's installer matrix.
- `validation:export` (`references/validation-export.md`) relies on Laravel internals: protected `Validator` methods
  such as `getMessage()` and `makeReplacements()`. After a Laravel minor upgrade, re-run the parity proof on a scratch
  spa app that requires this package by path.
- Boost's overrides and how to re-check them after a Boost upgrade: `references/boost.md`.
- A fix proven in a project built on these templates comes back here, with the project's name as `{{app}}`.
