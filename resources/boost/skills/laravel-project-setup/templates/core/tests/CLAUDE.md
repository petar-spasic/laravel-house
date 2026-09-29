# CLAUDE.md — tests

Scope: `tests/`. **Tests are end to end or not at all** (root `CLAUDE.md`).

## What is written

Only E2E tests, in `tests/E2E/`: one whole flow through its real entry point — an HTTP request through routing,
middleware, controller, database and the rendered page, redirect or Resource; an artisan command — asserting what a
person or an operator sees (the page, the flash, the payload, the records it leaves). One flow per test,
behaviour-named (`it('…')`), `uses(RefreshDatabase::class)`.

- Seed the world with the factories; drive it through the entry point, never by calling a service, model or job directly.
- Reference data is already there: `TestCase` carries `#[Seeder(ReferenceDataSeeder::class)]` (once per process).
  Look reference rows up by slug; never factory them.
- Fake only what is paid or outside: LLM calls (the SDK's fakes), outbound HTTP (`Http::fake` +
  `Http::preventStrayRequests()`). Nothing of our own code is faked, mocked or stubbed.
- The queues run inline in the suite (`QUEUE_CONNECTION=sync` in `phpunit.xml`), so a chain or a queued job runs for
  real inside the flow.
- Machine addresses come from the environment (e.g. the host of `LOCAL_APP_URL`), never a literal: a test that
  hardcodes this machine's IP fails on the next one. Stand-in addresses are RFC 5737 (`192.0.2.x`, `198.51.100.x`,
  `203.0.113.x`).
- Run only the E2E test you wrote or touched: `php artisan test --compact tests/E2E/…`.

## What is not

No unit tests, no component tests, no tier ladder, no fail-first ritual, nothing in `tests/Unit` or `tests/Feature`.
No suite is a gate before a commit. Correctness is shown on the real thing: the flow end to end, a read-only query or
measurement on the data, the output read.

## Gotchas that still bite an E2E test

- The suite runs on the sidecar's Postgres (`{{app}}_test`); a statement that fails inside the test's transaction
  aborts it — code expecting a failed insert wraps it in `DB::transaction()` for the savepoint.
- `phpunit.xml` sets no `DB_HOST`/`DB_PORT`: they come from `.env`, so a worktree's tests hit its own stack, never
  main's database.
- A test that reads a materialised view after seeding refreshes it first: nothing refreshes it inside a test.
- **Never fake an LLM agent with an empty queue**: the SDK then answers with random data for the schema. Queue a real
  answer for every call the flow makes.
- `SET LOCAL` is transaction-scoped, not savepoint-scoped: a setting made with it persists for the rest of the test's
  transaction.
- `Model::shouldBeStrict()` makes a lazy load throw: fix the eager load, never disable it.
<!-- if:htmx -->
- `TestCase::setUp()` calls `withoutVite()`; a test needing real `@vite` output opts back in with `$this->withVite()`.
<!-- endif -->
- Tests run one-request-per-process; production runs Octane — a passing test says nothing about state leaking across requests.
