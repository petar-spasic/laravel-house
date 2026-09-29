# CLAUDE.md — app/Jobs

Scope: `app/Jobs/`. The design standard is `app/CLAUDE.md`; project-wide facts
are in the root `CLAUDE.md`. Queues are Redis under Horizon
(`config/horizon.php` decides which queues get workers).

Jobs run off-request (`app/CLAUDE.md`, off-request rules): the id comes from
the constructor, never from ambient state.

- **One job per concern.** Separate jobs, separate failure domains, separate
  retry policies. A third-party outage in one concern must not cost you rows in
  another.
- **Record failure explicitly** (an `unreachable` / `failed` row or outcome
  enum) when the external system is down. Silence and inactivity must not look
  identical.
- Retries are per job and deliberate: `$tries`, `backoff()`, `retryUntil()`,
  `failed()`. Transient (429, timeout) retries; a validation or permanent
  failure does not.
- `timeout` on the job < Horizon's `timeout` < `retry_after` on the connection
  (root `CLAUDE.md`, Hosting), or a slow job runs twice.
- Long jobs drain work inside a **time budget** and chain themselves for the
  rest, rather than running unbounded.
- External systems go through a contract in `app/Contracts/` — the one seam an
  E2E test fakes.
