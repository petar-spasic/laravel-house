# Adopting the current core

Brings a house project set up on an earlier core onto the current one: core auth in every module, the API under
`/api/v1`, and Caddy as the local web server. Work in order; ask before each batch.

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
- The modules in use are named, from the root `CLAUDE.md`.
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
- Diff each rendered `CLAUDE.md` and `tests/E2E/*` file against the project's copy. Merge by hand, keeping the
  project's own text.
- Merge the rendered snippets the same way (`core-auth.md`).
- Never run the installer with `--force` on a live project: it would erase project-specific rules.
- Delete the render directory when done.

htmx: never turn Fortify's views on before the project's auth pages exist.
- No pages yet: keep `views => false` and `AcceptJson`.
- Pages built and views on: keep that state, and skip the rendered `FortifyViewRoutesTest` 405 assertions.

## 5. Routes

- Authenticated API routes live under `/api/v1` with `auth:sanctum`.
- spa: leave the Fortify prefix, any `Route::fallback` and `frontend/` as they are. Moving an existing frontend to
  adapter-node and `/api/auth` is a separate owner decision. Record it as a proposed card.

## 6. Hand off to laravel-deployment

Invoke laravel-deployment and run its whole Procedure, with the project's modules:
- its templates, merged against the project's files;
- its project files: `vite.config.js`, `phpunit.xml`, `bootstrap/app.php` (`TRUSTED_PROXIES`) and `.env`;
- its module references (spa, reverb, tenancy), and the API-only root lockfile;
- the Hosting section, filled again from its `references/hosting-section.md`. The rendered root `CLAUDE.md` has only
  the `{{hosting}}` placeholder there, so the project's old Hosting text stays until this replaces it.

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
