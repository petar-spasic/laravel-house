# Adopting the current core

Brings a house project set up on an earlier core onto the current one: core auth in every module, the API under
`/api/v1`, and Caddy as the local web server. Work in order; ask before each batch. Moving a project to another module
set is Switching modules, below.

## 1. Update the package first

This file must exist in the project's `.claude/skills/laravel-project-setup/references/`. If it does not, the
project's copy of the house skills is old:

```shell
composer require --dev petar-spasic/laravel-house
php artisan boost:update
```

Give no version constraint: a `^0.x` caret never crosses a minor version. Then restart Claude Code, so the new skill
loads.

## 2. Preconditions

- `main` is clean.
- The modules in use are named, from `config/house.php` (or, before Rendered rules, the root `CLAUDE.md`).
- You know the project's `--set` values: `app`, `laravel_version`, `php_version`, `pest_version`.

Tenancy is not part of adopting. Adding it to an app with data is an owner decision and a data migration.

## 3. Approval batch

- `php artisan install:api --without-migration-prompt`, if Sanctum is missing.
- `laravel/socialite`, if missing.
- `HasApiTokens` and any other missing `User` trait (`core-auth.md`, User model).
- The `bootstrap/app.php` and `config/sanctum.php` merges (`core-auth.md`).
- The module pins in `modules.md`, where the project's versions are older: htmx `vite@^8.1`, islands
  `typescript@^6`. An older Vite ignores `server.ws`, and the HMR socket falls to PHP.

Then check `withRouting` in `bootstrap/app.php`: it has `api: __DIR__.'/../routes/api.php'` and
`apiPrefix: 'api/v1'`. If not, add both by hand (SKILL.md Gotchas).

## 4. Render and merge

Render the templates outside the project, with its modules and `--set`s:

```shell
php "${CLAUDE_SKILL_DIR}/scripts/install.php" . --modules=htmx,islands --set app=acme \
  --set laravel_version=13 --set php_version=8.5 --set pest_version=5 --render-to="$(mktemp -d)"
```

- It writes nothing into the project, and its first output line names the render directory.
- It renders every template, including those the project already has.
- Every rendered file counts, `config/boost.php` included. Copy what the project lacks. Diff what it has against the
  project's copy and merge by hand, keeping the project's own text. The house files `php artisan house:update` writes
  (the `CLAUDE.md` house spans and `.ai/`) need no merge: run it, then `php artisan boost:update`.
- Merge the rendered snippets the same way (`core-auth.md`).
- Never run the installer with `--force` on a live project: it would erase project-specific rules.
- Delete the render directory when done.

htmx: never turn Fortify's views on before the project's auth pages exist.
- No pages yet: keep `views => false` and `AcceptJson`.
- Pages built and views on: keep that state, render with `auth-pages` in `--modules`, and skip the rendered
  `FortifyViewRoutesTest` 405 assertions.

## 5. Routes

- Authenticated API routes live under `/api/v1` with `auth:sanctum`.
- spa: leave the Fortify prefix, any `Route::fallback` and `frontend/` as they are. Moving an existing frontend to
  adapter-node and `/api/auth` is a module switch the owner decides (Switching modules).

## 6. Hand off to laravel-deployment

Invoke laravel-deployment and run its whole Procedure, with the project's modules:
- its templates, rendered with the project's modules and merged against the project's files;
- its snippets: `vite.config.js`, `phpunit.xml`, `.env` and the tenancy project files; and the API-only root
  lockfile. Trusted proxies move from `bootstrap/app.php` to `config('app.trusted_proxies')`, applied in
  `AppServiceProvider::boot()` (setup's `AppServiceProvider-boot.php` snippet);
- the Hosting section, filled again from its rendered `hosting-section.md` snippet. The rendered root `CLAUDE.md` has
  only the `{{hosting}}` placeholder there, so the project's old Hosting text stays until this replaces it.

The current local image serves through Caddy, so the merge also:
- removes the earlier local web server's config file under `docker/` once the owner confirms;
- drops that server's supervisor program, by taking the template's local entrypoint;
- drops the `server.proxy` block from `vite.config.js`.

Rebuild with `docker compose … up -d --build`.

## 7. Verify

- `php artisan test --compact tests/E2E` passes against the stack.
- The stack is healthy. Through Caddy, `/up` answers 200 and `/api/v1/x` answers Laravel's JSON 404, never a 502.
- htmx: the page loads from the LAN URL with every asset answering 200.

Commit only after the owner agrees (SKILL.md step 10).

## Rendered rules

Moves a house project whose `CLAUDE.md` files are plain text onto rules rendered by every `composer update` (SKILL.md
intro). A project without `config/house.php` is in this state. Run it after step 1, before any other step. Ask before
each batch.

1. Run `php "${CLAUDE_SKILL_DIR}/scripts/install.php" . --adopt` with the project's `--modules` and step 4's
   `--set`s (with `auth-pages` when its htmx auth pages are built and Fortify's views are on). It writes
   `config/house.php` and puts `house:update` right before `boost:update` in composer's `post-update-cmd`, nothing
   else. Then render the templates outside the project with the same flags and `--render-to` in place of `--adopt`
   (step 4): steps 2, 3 and 4 compare against that `<dir>`.
2. Each layer `CLAUDE.md` (every rendered `CLAUDE.md` but the root): take the rendered file, then add after its
   `<!-- house:end -->` line what the project's copy says that the rendered one does not, as this project's rules.
   A project line that contradicts a house line goes to the owner: the house line stands, or the file is listed under
   `overrides` in `config/house.php` and keeps the project's text whole.
3. The root `CLAUDE.md`: keep its title, scope, What we are building, Hosting, any section of the project's own, and
   the Boost block. Delete the house sections, which now come from the package's guidelines: the tests rule,
   Conventions, Where the docs live, Non-negotiables, Stack, Using Boost, Auth, Tenancy and Frontend. A project
   change to one of them goes to the owner as in step 2: a section of the project's own above the Boost block, or an
   override of that topic, `.ai/guidelines/petar-spasic/laravel-house/<topic>.blade.php`.
4. Run `php artisan house:update --check`. It rewrites each `.ai/` file it names whole, so diff each against
   `<dir>`'s copy first. A project addition moves to a file of the project's own, which the update never touches
   (`.ai/guidelines/{{app}}.blade.php`), or its path goes under `overrides`.
5. Run `php artisan house:update`, then `php artisan boost:update`. Then `php artisan house:update --check` prints
   nothing, and `php "${CLAUDE_SKILL_DIR}/scripts/verify.php" .` prints nothing.
6. Read `git diff` with the owner: the project's own rules are all still there. Commit only after the owner agrees.

## Switching modules

Moves a house project from one module set to another, such as htmx to spa. Tenancy is never switched this way (step
2). Ask before each batch.

1. The owner names the new module set. The SKILL.md Modules table must accept it.
2. Set the new modules in `config/house.php`, without `auth-pages` once htmx goes, and run
   `php artisan house:update`, then `php artisan boost:update`: the house rules follow, and the update names each
   house file of a leaving module to remove by hand. Render the templates with the new `--modules` and merge every
   other rendered file as in step 4: the leaving module's text goes, the arriving module's text comes in, the
   project's own text stays.
3. Undo the leaving module's row in `modules.md` (its Writes, Packages and Wiring), then apply the arriving module's
   row: its Writes come from the render, then its Packages, Deletes and Wiring.
   - htmx or islands to spa deletes the root Node toolchain and `resources/js`.
   - spa to htmx restores them. `frontend/` goes only after the owner agrees.
4. Apply `core-auth.md` for the arriving module: the Fortify prefix (`/api/auth` with spa), `statefulApi()` on or off,
   and Fortify's views.
5. Run laravel-deployment's Procedure for the new module set (step 6): its module references, Caddyfiles, compose
   files and the Hosting section.
6. Verify as in step 7, plus the arriving module's checks in `modules.md`.
