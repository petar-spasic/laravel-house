# CLAUDE.md — app/Listeners

Scope: `app/Listeners/`. The design standard is `app/CLAUDE.md`; project-wide
facts are in the root `CLAUDE.md`.

Listeners run with NO bound request context — they fire from jobs, commands and
queued pipelines as well as HTTP (`app/CLAUDE.md`, off-request rules). So:

- Take everything from the **event payload**, never from ambient state. The
  user/entity id comes from the event, always.
- Wired by one `Event::listen` line (`app/Providers/CLAUDE.md`); without it the
  listener silently never runs — run the flow to see the event reach it.
- A listener may **dispatch** a job; it must not do heavy work itself. Long
  work is a job with its own retry policy and failure domain
  (`app/Jobs/CLAUDE.md`).
- A listener must never call an external system directly — go through the
  contract-backed client, which carries the timeout and bounded retries.
