# CLAUDE.md — app/Support (security-relevant helpers)

Scope: `app/Support/`. The design standard is `app/CLAUDE.md`; project-wide
facts are in the root `CLAUDE.md`.

Small, and every file here is a security or API-shape boundary — none of it is
casual utility code. Don't grow this directory: a helper with a domain meaning
belongs in a service.

What lives here (create each when first needed, in this shape):

- **`Redaction`** — the ONE list of secret-bearing key patterns, used by
  structured logging and by anything that persists a payload snapshot. Add new
  patterns HERE, never to a second list; a divergent copy is a leak waiting to
  happen.
- **The HMAC signer/verifier** — one implementation, constant-time comparison
  (`hash_equals`), used by the middleware and by any client that signs outbound
  calls.
<!-- if:htmx -->
- **`Markdown::render()`** — `Str::markdown` with raw HTML escaped
  (`html_input: escape`), unsafe links (`javascript:` and co.) dropped and
  nesting capped at 100. It is the **only** thing whose output may go through
  `{!! !!}` in Blade
<!-- if:islands -->
  or `{@html}` in an island
<!-- endif -->
  (root `CLAUDE.md`); loosening an option is a security change.
<!-- endif -->
<!-- if:spa -->
- **`Markdown::render()`** — `Str::markdown` with raw HTML escaped
  (`html_input: escape`), unsafe links (`javascript:` and co.) dropped and
  nesting capped at 100. It is the **only** thing whose output may go through
  `{@html}` in the SPA (root `CLAUDE.md`); loosening an option is a security
  change.
<!-- endif -->

Rules:

- Nothing here reaches the network or the filesystem — external systems go
  behind a contract in `app/Contracts/`.
- Pure and stateless — safe under Octane by construction. No static caches.
- Anything here is used by more than one surface, so a change has the widest
  blast radius in the app. Expect to update `app/CLAUDE.md` in the same commit.
