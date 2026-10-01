# Core auth

The setup-time wiring for Fortify, Sanctum and Socialite, in every project. The rules themselves are the root
`CLAUDE.md`, Auth, and the layer files it names; this file never restates them.

Every snippet named here is the rendered copy from `install.php --render-to=<dir>` (SKILL.md step 4), under
`<dir>/snippets/`.

## Sanctum

- `php artisan install:api --without-migration-prompt` installs Sanctum, writes `routes/api.php` and publishes
  `config/sanctum.php`. Without the flag it asks to migrate, and `--no-interaction` answers yes against whatever
  database the host `.env` names.
- It adds `api:` to `bootstrap/app.php` only when a `web:` line or a `// api:` line is there. Otherwise it only warns
  (SKILL.md Gotchas).
- Merge `config-sanctum.php` into `config/sanctum.php`. Its `guard` is `['web']` with spa and `[]` everywhere else, so
  only spa's API accepts the session cookie.

## bootstrap/app.php

Merge `bootstrap-app.php`. Every module gets:
- `api: __DIR__.'/../routes/api.php'` and `apiPrefix: 'api/v1'` (with htmx, next to `then:`);
- no event discovery;
- `AcceptJson` ahead of `auth` in the middleware priority;
- JSON errors for `api/*`.

htmx adds its `public` group and `htmx` alias. spa adds `statefulApi()`, and with reverb the `withBroadcasting(…)`
call.

Check: `php artisan route:list --path=api/v1` lists `api/v1/user`, the closure `install:api` wrote. Then delete that
closure from `routes/api.php`.

## Fortify

- Merge `config-fortify.php` into `config/fortify.php`: `views => false`, and `middleware => ['web', AcceptJson::class,
  'throttle:auth-forms']`. The installer wrote `app/Http/Middleware/AcceptJson.php`.
- spa: the same file sets `prefix => 'api/auth'` and `home => '/'`.
- Merge `FortifyServiceProvider-boot.php` into `FortifyServiceProvider`. It sets the reset-link URL and the
  `auth-forms` limiter; with spa it also points the verify-email link at the frontend.
- htmx: the views stay off until the project has built its Blade pages. With views on and no pages, every GET auth
  page answers 500. The commit that adds the last page turns them on (`resources/CLAUDE.md`, Auth pages). Until then
  `tests/E2E/FortifyViewRoutesTest.php` asserts 405 on the GET pages.
- API-only and spa: the client renders every auth page, so the views stay off for good.

## User model

`User` gets:
- `TwoFactorAuthenticatable`, `PasskeyAuthenticatable` and `implements PasskeyUser`;
- `HasApiTokens`;
- `two_factor_secret` and `two_factor_recovery_codes` in `#[Hidden]`;
- `two_factor_confirmed_at` cast to `datetime`.

## Passkeys

- Fortify 1.40 and later turns passkeys on. The house keeps them: the `User` traits, `TwoFactorAuthenticationTest`,
  the routes and Middleware rules, and a vendor-owned bigint `passkeys` table (like `jobs`).
- A passkey sign-in skips the two-factor challenge. Tell the owner. The root `CLAUDE.md`, Auth, gives the two fixes
  for a project that needs 2FA to hold.
- Locally, passkeys work only on the localhost origin in `APP_URL` (root `CLAUDE.md`, Hosting).

## Socialite

- Setup installs `laravel/socialite` and nothing else.
- The `social_accounts` table, the routes, the provider keys and the nullable-password migration come when the
  project turns a provider on. The rules are the root `CLAUDE.md`, Social sign-in, and `routes/CLAUDE.md`.
- Which providers are on is a proposed card (SKILL.md step 11).
- The callback URLs: root `CLAUDE.md`, Hosting, which laravel-deployment fills.

## Tests shipped

- `tests/E2E/FortifyViewRoutesTest.php`, with a variant per module.
- `tests/E2E/TwoFactorAuthenticationTest.php`.
