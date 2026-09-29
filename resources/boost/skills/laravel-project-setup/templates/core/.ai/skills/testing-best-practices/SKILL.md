---
name: testing-best-practices
description: "This app's test rules: tests are end to end or not at all. Use before writing, naming, structuring or reviewing any test, choosing what to fake, or deciding whether a change needs a test."
---

# Tests are end to end or not at all

- No unit tests, no component tests, no tier ladder, no fail-first ritual: none are written, run or updated, and no
  suite is a gate before a commit.
- Correctness is shown on the real thing: the flow end to end, a read-only query or measurement on the data, the
  output read.
- When a test is worth writing it is an E2E test in `tests/E2E/`: one whole flow through its real entry point — an
  HTTP request through routing, middleware, controller, database and the rendered page, redirect or Resource; an
  artisan command — asserting what a person or an operator sees.
- One flow per test, behaviour-named (`it('…')`), `uses(RefreshDatabase::class)`. Seed the world with the factories
  and drive it through the entry point, never by calling a service, model or job directly.
- Fake only what is paid or outside: LLM calls (the SDK's fakes), outbound HTTP (`Http::fake` +
  `Http::preventStrayRequests()`). Nothing of our own code is faked, mocked or stubbed.
- Run only the E2E test you wrote or touched: `php artisan test --compact tests/E2E/…`.

The full rules and the gotchas: `tests/CLAUDE.md`.
