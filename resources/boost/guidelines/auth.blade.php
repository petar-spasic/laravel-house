@houserules
## Auth

This section owns the auth model; the layer files hold routes, schema, models and proofs.

**Fortify** runs every sign-in, sign-up, reset, two-factor and passkey flow (`app/Actions/Fortify/`). Sessions live
in Redis; no JWT, no stateless browser auth.
@houserules('htmx')
@unlesshouserules('auth-pages')
Its views stay off, with `AcceptJson`, until the project has built its Blade pages. The commit that adds the last
page turns them on (`resources/CLAUDE.md`, Auth pages).
@endhouserules
@houserules('auth-pages')
Its views are on: they serve the Blade auth pages (`resources/CLAUDE.md`, Auth pages).
@endhouserules
@endhouserules
@houserules('spa')
It is mounted at `/api/auth` and answers JSON only; the pages are the SvelteKit app's.
@endhouserules
@unlesshouserules('htmx')
@unlesshouserules('spa')
Its views are off and it answers JSON only; the client renders the pages.
@endhouserules
@endhouserules

**Passkeys**
- Never turn passkeys off. Locally they work only on the localhost origin in `APP_URL` (Hosting).
- A passkey sign-in skips the two-factor challenge, and the house accepts that. A project that needs 2FA for
  passkey users picks one:
  - refuse passkey sign-in for users with confirmed 2FA:
    `Passkeys::authorizeLoginUsing(fn ($request, $user) => $user->two_factor_confirmed_at === null)`. The hook only
    allows or refuses; it cannot redirect.
  - or bind its own `Laravel\Passkeys\Contracts\PasskeyLoginResponse`: sign out, put `login.id` and `login.remember`
    in the session, answer `{redirect: <the challenge>}`, with its own E2E proof.

**Confirmation** (`password.confirm`) passes with the password or a passkey, nothing else. A user with neither (a
social-only sign-up) sets a first password through "Forgot password" while signed out.

**Sanctum**
- Every authenticated API route uses `auth:sanctum`, never `auth:web`. `User` has `HasApiTokens`.
@houserules('spa')
- The SvelteKit app authenticates by the session cookie (`statefulApi()`; `SANCTUM_STATEFUL_DOMAINS` lists every
  origin the app is browsed at, Hosting). Every other client uses a token.
@endhouserules
@unlesshouserules('spa')
- `/api/v1` is token-only: `config/sanctum.php` sets `'guard' => []`, so a session never authenticates there.
@endhouserules

### Social sign-in (when the project builds it)

Socialite is installed everywhere; social sign-in is built when a provider is turned on.
- Providers come from `services.socialite.providers` (`SOCIAL_PROVIDERS`, empty by default). Apple, Microsoft and any
  SocialiteProviders package need the owner's approval; Socialite's own drivers do not.
- Accounts live in `social_accounts`, matched on `provider` + `provider_user_id`, **never on email**.
- The flow runs in the `web` group with the OAuth state in the session; never `stateless()`.
- `redirect` stores the flow (sign-in or link) in the session, plus the signed-in user's id for a link. The callback
  pulls them once and refuses when they are missing or the session user changed.
- No linked account: a user is created only when registration is on and no user has the email.
  - The provider vouches for the email: the user is created now, with a null password and `email_verified_at` now.
  - It does not: **verify first, create after.** The callback creates nothing. It keeps the pending sign-up
    (provider, `provider_user_id`, email; no tokens) in the session and mails a signed, expiring link to that
    address. Opening the link in that same session creates the user, verified, and its provider link; opened
    anywhere else it does nothing. Never create an unverified user with a provider link attached: whoever later
    proves the inbox would own an account the provider's user still signs in to.
- **Vouching:** Google and LinkedIn-OpenID only when the raw `email_verified` claim is `true`; Apple when its ID
  token's `email_verified` is `true` (private relay addresses included); GitHub only with its default `user:email`
  scope (never `setScopes()` without it); no other provider.
- **Never link by email**: it hands the account to whoever controls that address at the provider. An existing user
  with that email signs in their usual way and links the provider from their account.
- Linking needs `password.confirm`. An account linked to another user is refused. Unlinking never removes the last
  way to sign in: with no password set and no registered passkey, checked when the unlink runs, it is refused. The
  confirmation it sits behind already needs one of the two, so only a passkey deleted after confirming reaches this
  guard; it has no E2E proof.
- Success is `Auth::login`, then `session()->regenerate()`. A user with confirmed 2FA is not signed in: the callback
  puts `login.id` and `login.remember` in the session and sends them to Fortify's challenge.
@houserules('spa')
- A sign-in Laravel completes (the callback, the mailed confirmation link) redirects to the SvelteKit `/signed-in`
  page, whose browser code settles the session and sets the `signed_in` marker (`frontend/CLAUDE.md`). Laravel never
  sets the marker.
@endhouserules
- Provider tokens are stored only when the project calls the provider's API, encrypted.
- Building it includes a migration that makes `users.password` nullable (`database/CLAUDE.md`).
@houserules('htmx')
- It is built after the auth pages: linking needs the confirm-password page, the 2FA hand-off the challenge page.
@endhouserules
@endhouserules
