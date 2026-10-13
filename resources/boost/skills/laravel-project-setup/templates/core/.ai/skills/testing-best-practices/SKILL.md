---
name: testing-best-practices
description: "This app's test rules: tests are end to end, and only where a failure hurts. Use when deciding whether a change gets a test, and before writing, naming, structuring, deleting or reviewing any test or choosing what to fake."
---

# Tests are end to end, and only where a failure hurts

- No unit, component or feature tests, no tier ladder, no fail-first ritual: none are written, run or updated, and no
  suite is a gate before a commit.
- An E2E test is written only for:
  - **Security:** authentication, authorization (each write flow's denial proof), what a response must never leak,
    rate limits, the signature check of an inbound webhook, uploads.
  - **Data validity:** what is validated before it is stored, invariants of stored data, money arithmetic, seeders
    and migrations or backfills that change data.
<!-- if:spa -->
    The frontend's validation parity with the backend (`tests/CLAUDE.md`, Validation parity).
<!-- endif -->
  - **Critical flows:** sign-in, registration, password reset, email verification, two-factor, and the app's own core
    paths without which it does not work (checkout, payment).
<!-- if:tenancy -->
  - **Tenancy isolation:** the tenancy proofs (`tests/CLAUDE.md`, Tenancy).
<!-- endif -->
- Nothing else gets a test, a browser spec least of all: layout, copy, styling, list rendering, navigation, and
  frontend behaviour that touches none of these groups. Check such a change on the real thing (curl the page, read it
  in the browser, run the artisan command) and cite the check; do not keep it as a test. A green suite is not proof a
  change works.
- An existing test outside the groups stays until a change breaks it; then delete it instead of fixing it, and name
  it in the report.
- A Pest E2E test lives in `tests/E2E/`: one whole flow through its real entry point — an HTTP request through
  routing, middleware, controller, database and the rendered page, redirect or Resource; an artisan command —
  asserting what a person or an operator sees.
- One flow per test, behaviour-named (`it('…')`), `uses(RefreshDatabase::class)`. Seed the world with the factories
  and drive it through the entry point, never by calling a service, model or job directly.
- Fake only what is paid or outside: LLM calls (the SDK's fakes), outbound HTTP (`Http::fake` +
  `Http::preventStrayRequests()`). Nothing of our own code is faked, mocked or stubbed.
- Run only the E2E test you wrote or touched: `php artisan test --compact tests/E2E/…`.

The full rules and the gotchas: `tests/CLAUDE.md`.
