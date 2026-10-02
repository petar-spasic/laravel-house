# Tenancy — the `tenancy` module only

Without the module, the app connects as the compose superuser `{{app}}`, and row-level security (RLS) never binds it.
The tenancy rules (tables, middleware, jobs, proofs) live in the generated `CLAUDE.md` files (laravel-project-setup,
`if:tenancy`); this file is the database side's why, traps and checks. The templates' `tenancy` blocks and snippets
carry the changes (SKILL.md, Procedure). A stack that already has a database volume then follows Existing volumes.

## Roles

Who is who: the root `CLAUDE.md`, Hosting (its tenancy part).

- Both role names are fixed. Only the passwords are env: `DB_OWNER_PASSWORD` and `DB_PASSWORD`.
- Every `migrate*` runs as `{{app}}`: `ALTER DEFAULT PRIVILEGES FOR ROLE {{app}}` grants only on tables that exactly
  that role creates.
- The superuser owns the tables for three reasons. An existing volume already has it as owner. It keeps CREATE on
  `public` and on extensions. A cross-tenant data migration sees every row.
- FORCE is still set on each tenant-owned table. It does nothing for a superuser, and it holds if the tables ever move
  to a non-superuser owner.
- Default privileges are per database: `roles.sql` covers `{{app}}` and, where it exists, `{{app}}_test`.
- No role but the owner skips RLS. A platform admin is a decided card (`app/Http/Middleware/CLAUDE.md`).

## In the templates

- `docker/postgres/roles.sql`: the app role and its grants; idempotent. Both compose files mount it into initdb, after
  `init-test-db.sql`, so the test database exists when it runs. The postgres service carries `DB_PASSWORD`, the app
  role's password.
- The app connects as `{{app}}_app`; the prod compose's `POSTGRES_USER` is the fixed `{{app}}`, with
  `DB_OWNER_PASSWORD`.
- Every `migrate*` takes `--database=pgsql_owner`: both entrypoints and `docker/e2e.sh`. The database wait and the
  seed stay on the default connection. The local `users`-empty check still holds, because `users` is global.
- The board's migrate command is one of them: publish `config/kanban.php` and set
  `'migrate' => 'php artisan migrate --force --database=pgsql_owner'` (`finish` and the card agents run it).
- Snippets to merge: `env.dotenv` (the host `.env` and `.env.example`; a worktree's generated `.env` needs the same
  three `DB_*` lines for host-side `artisan` and tests), `config-database.php` (`pgsql_owner`, no `url`: the Hosting
  text says why) and `TestCase-artisan.php`. Without the last, `RefreshDatabase` migrates as the app role, which cannot
  drop or create tables, and the shipped tests fail.
- Both Dockerfiles copy the entrypoint into the image: on a running stack, apply the change with `up -d --build`,
  never a bare `up -d`.

The Octane reset of `app.tenant_id` (`config/octane.php`) comes with its listener class, in the tenancy foundation
card (`app/CLAUDE.md`, Octane). Never add it before the class exists.

## Existing volumes

initdb runs `roles.sql` only on an empty volume. Every volume that already exists, worktree stacks' included, takes
these steps once. Each `docker compose …` takes `-f docker-compose.local.yml` (local) or `--env-file .env.prod` (prod).

1. Make the template, env and project-file changes above. The volume's superuser must be named `{{app}}`. Prod:
   `DB_OWNER_PASSWORD` takes the old `DB_PASSWORD` (the volume keeps the password it was created with), and
   `DB_PASSWORD` becomes a new password for the app role.
2. `docker compose … up -d postgres`: the mount and `DB_PASSWORD` reach the container.
3. `docker compose … exec -T postgres sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f /docker-entrypoint-initdb.d/roles.sql'`.
4. `docker compose … up -d --build app`.
5. Local: `docker compose -f docker-compose.local.yml exec app php artisan db:seed`. Once `users` has rows, the boot's
   `auto` seed runs only `ReferenceDataSeeder`; the dev tenants and memberships exist only after this (DevSeeder is
   idempotent). Prod: existing users have no membership until the project's membership card gives them one.

After a `DB_PASSWORD` change, repeat steps 2 to 4: a `restart` keeps the container's old password.

## Verify

1. `select rolsuper, rolbypassrls from pg_roles where rolname = '{{app}}_app'` returns `f|f`.
2. `\ddp` in `{{app}}` and in `{{app}}_test` shows `{{app}}` granting `arwd` on tables and `rU` on sequences to
   `{{app}}_app`.
3. `docker compose -f docker-compose.local.yml exec app php artisan tinker --execute 'echo DB::selectOne("select current_user as u")->u;'`
   prints `{{app}}_app`, and the stack is healthy (the boot migrated as the owner).
4. `php artisan test` in the container passes and leaves the dev rows untouched. Once the tenancy card is built, the
   proofs pass too, and EXPLAIN of a tenant query as the app role shows an Index Cond on `tenant_id`.
5. Restart the app container twice: healthy both times. Prod shape on a fresh volume: the same role check, and
   `roles.sql` skips the absent test database.

## Traps

- **Every tenant sees every row** → `POSTGRES_USER` and `DB_USERNAME` name the same role, a superuser: the app bypasses
  RLS. The app connects as `{{app}}_app`.
- **The boot ends in `database unreachable: … password authentication failed for user "{{app}}_app"`** → `roles.sql`
  never ran on this volume, or ran with another `DB_PASSWORD`: Existing volumes, steps 2 to 4.
- **A fresh postgres exits with `roles.sql: DB_PASSWORD is unset or empty`, and the next start is healthy but the app
  role is missing** → the postgres service lacks `DB_PASSWORD`. A failed init still leaves the volume initialised, so
  initdb never runs again. Set it, then Existing volumes, steps 2 to 4.
- **Every `tenant` route answers 403 `no_tenant` on a stack that predates the module** → its users have no
  membership: Existing volumes, step 5.
- **The boot's migrate fails with `password authentication failed for user "{{app}}"`** → `DB_OWNER_PASSWORD`
  differs from the password the volume was created with; Postgres sets it only at first init. Set it back, or run
  `ALTER ROLE {{app}} PASSWORD …` first.
- **`permission denied for table`** → a role other than `{{app}}` created the table, or it was created before
  `roles.sql` ran on that database. Run `roles.sql` again.
- **`permission denied for schema public` or `must be owner of table`** → a `migrate*` ran as the app role: every one
  takes `--database=pgsql_owner`, the tests' through `TestCase::artisan()`.
- **`new row violates row-level security policy`** → the write ran with no tenant or another tenant set, a seeder
  outside `CurrentTenant::run` included.
