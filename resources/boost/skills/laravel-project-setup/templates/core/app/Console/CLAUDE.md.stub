# CLAUDE.md — app/Console (commands)

Scope: `app/Console/`. The design standard is `app/CLAUDE.md`; project-wide
facts are in the root `CLAUDE.md`; scheduling is in `routes/CLAUDE.md`.

Commands are the scheduler's entry points and the operator's tools.

- **Signatures are a stable API** — `routes/console.php`, ops docs, cron and
  alerting reference them; renaming one is a breaking change. Namespace them
  `app:` / a project prefix, never bare verbs.
- Commands run off-request (`app/CLAUDE.md`): the entity a command acts on
  comes from its argument or option, never from ambient state.
- **Dispatch to a queue; never do the work inline.** A synchronous loop over
  many items blocks the scheduler, and one hung item stalls the rest.
- Recurring sweeps are **idempotent and re-runnable**, take a narrowing option
  (`--id=` / `--only=`) for operator use, and offer `--dry-run` when they
  write.
- Operator actions that change state (pause, retract, republish) are **logged
  rows, never quiet fixes** — timestamp, actor, what, why.
- Output through `$this->components->*` for humans; exit codes for cron.

New or changed scheduled command ⇒ update `routes/CLAUDE.md` in the SAME
commit.
