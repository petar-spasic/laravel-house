# CLAUDE.md — app/Enums

Scope: `app/Enums/`. The design standard is `app/CLAUDE.md`; project-wide facts
are in the root `CLAUDE.md`.

Enums are the domain vocabulary — string-backed, TitleCase cases. Codes and
human display labels are canonical HERE (`label()`, per-case predicates), never
duplicated in controllers, resources, or Blade.

Lifecycle enums (any status column) own the state machine as methods —
`canTransitionTo()`, `allowedTransitions()`, terminal-state and named domain
predicates — and no status string literal exists outside them (the state
discipline: `app/Models/CLAUDE.md`).

<!-- if:htmx -->
Blade renders `$model->status->label()`, never a `match` on `->value` in the
template; a display concern that needs colour/icon per case is a method here
(`badgeClass()`), so the mapping exists once.

<!-- endif -->
Outcome enums (a job's result, a sync's status) must carry an explicit
`Unreachable` / `Failed` case distinct from "nothing happened" — silence and
inactivity must not look identical in the data.

**Never enumerate secrets or external identifiers** (API model names, vendor
account ids) as cases; those live in config.
