# CLAUDE.md — app/Models

Scope: `app/Models/`. The design standard (fat models, records that must not be
rewritten) is in `app/CLAUDE.md`; migration conventions in `database/CLAUDE.md`.

## File conventions

`final class`, `declare(strict_types=1)`, full `@property` docblocks, the
`casts()` **method** (never `$casts`), `$fillable` allow-lists (never
`$guarded`). Observers registered via `Model::observe()` in a service provider
`boot()`. Primary keys are Stripe-style prefixed ids: `use HasPrefixedId;` + `public const ID_PREFIX = 'bd';` (2–3 lowercase letters, unique across models); `User` and the hot tables listed in `database/CLAUDE.md` are the bigint exceptions. `User` is also exempt from the file conventions (Fortify skeleton: not `final`, `#[Fillable]` attributes) — leave it.

**State discipline** — every status-bearing model ships as ONE unit:

1. A backed enum owning the lifecycle: `canTransitionTo()`,
   `allowedTransitions()`, terminal-state predicates, and **distinct named
   predicates for distinct domain questions** rather than ad-hoc status lists
   at call sites.
2. The column cast to that enum and REMOVED from `$fillable` (comment why).
3. `transitionTo(Enum $target)` as the only writer — pre-check
   `canTransitionTo()` (the controller maps a refusal → 422), `forceFill()`
   past the non-fillable column.
4. The FormRequest half: `['prohibited']` on the same column.
5. NO status string literals outside the enum — not in controllers, services,
   Blade, or FormRequest `in:` rules (`Rule::enum()`).

**Scopes:** a query predicate lives ONCE as a model scope, named for its domain
meaning; every consumer including ListQuery services calls it. Never re-inline a
copy.

## Integrity rules

Which tables are append-only records vs. ordinary mutable rows is a domain
decision (`app/CLAUDE.md`, "Records that must not be rewritten"). **List the
append-only models here as they are decided**, with the rule they carry:

- _(none yet)_

All of these `use AppendOnly` (`Models/Concerns/AppendOnly.php`, created with
the first of them): `updating` / `deleting` throw `LogicException`;
`getUpdatedAtColumn()` returns null; a sanctioned write wraps itself in
`permittedWrite()`.

For any such model: corrections are new rows superseding old ones
(`supersedes_id`); never update in place, never delete; observation time is a
real column distinct from `created_at`.

## Rules of engagement

- Models never reach out — no HTTP client, no shell, no filesystem walk in a
  model. External systems go behind a contract in `app/Contracts/`.
- Heavy reads (time series, minute-cadence tables) are sampled or aggregated in
  SQL via a `Concerns\` trait on the model, never hydrated whole on the request
  path. Loading a whole series with `get()` is the bug that trait exists to
  prevent.
- Models carrying secrets (API tokens, OAuth refresh tokens, webhook secrets)
  must mark them `$hidden` **and** never be returned raw from a controller —
  the Resource is the actual leak boundary (`app/Http/CLAUDE.md`). Encrypt them
  at rest with the `encrypted` cast.
- `User` is Fortify's: `TwoFactorAuthenticatable` and `PasskeyAuthenticatable` (with the `PasskeyUser` contract) stay on it, the two-factor secret and recovery codes stay `Hidden`; profile and
  password rules live in `app/Actions/Fortify/`, not in the model.
- Any semantic change here updates `app/CLAUDE.md` and `database/CLAUDE.md` in
  the SAME commit.
