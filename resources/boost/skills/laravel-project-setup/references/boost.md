# Boost

How setup configures Boost (SKILL.md step 7), how the house rules reach the root `CLAUDE.md`, and how the house
overrides are kept current.

## Configure

- `install.php --fresh` writes `boost.json` with only three keys before the first install (SKILL.md Gotchas):
  `"agents": ["claude_code"]`, `"cloud": false`, and `"packages"` with `"petar-spasic/laravel-house"`. It also ends
  `composer.json` `post-update-cmd` with `@php artisan house:update --ansi`, then `@php artisan boost:update --ansi`,
  so `composer update` renders the house files, then the guidelines and skills. The order matters: Boost composes its
  block from the `.ai/` files `house:update` writes. An existing project gets both from `install.php --adopt`
  (`references/adopt.md`, Rendered rules).
- Run `php artisan boost:install --no-interaction` yourself, never through `!` (SKILL.md Gotchas).
- The project's copy of the house skills is the pinned one. Disable the plugin for the project:
  `claude plugin disable laravel-house@laravel-house --scope project`.

## The house guidelines

The root `CLAUDE.md`'s house rules are Boost package guidelines: the package's `resources/boost/guidelines/*.blade.php`
(`auth`, `core`, `frontend`, `tenancy`). Boost renders them into its block on every `boost:update`, as
`=== petar-spasic/laravel-house/<topic> rules ===`.
- `@houserules` holds only with `config/house.php`, so a project that lists the package for its skills alone gets
  none of them; `@houserules('htmx|spa')` holds when one of those modules is on. A topic's first line is its gate,
  which `scripts/verify.php` reads to know the topics a project must have. The directive reads `config/house.php`
  through `scripts/HouseConfig.php`, as `install.php` does, never through a cached config; a module set it refuses
  fails the render, and `boost:update` names the file.
- A project overrides a topic whole with `.ai/guidelines/petar-spasic/laravel-house/<topic>.blade.php`.
- They are Blade, rendered by Boost's `RendersBladeGuidelines`. It hides backticks, `<?php`, `<x-` and a few
  directive names from Blade, and keeps `@@`, `@{{` and `&` in a fence as written; `{{ }}` is evaluated everywhere,
  inline code included. A guideline Blade cannot render, a literal `{{ }}` or an undefined variable, is dropped
  without a word. `HouseRulesTest` runs a real `boost:update` for every module set and fails on such a drop.

## Check

`scripts/verify.php` (SKILL.md step 8) checks the guidelines block and the skills. `infer-conventions` is the house
stub. After a Boost upgrade, the overrides differ from upstream only by the hunks listed below:
`for f in foundation boost/core laravel/core; do diff vendor/laravel/boost/.ai/$f.blade.php .ai/guidelines/$f.blade.php; done`.

## Overrides

The templates override parts of Boost. Re-check each one after a Boost upgrade.

`house:update` writes every file under `.ai/` again from the package, so each project follows the overrides as they
change here, except a path its `config/house.php` lists under `overrides`.

- `.ai/guidelines/foundation`, `laravel/core` and `boost/core` are Boost 2.10.3's with these hunks changed. After an
  upgrade, carry the new upstream text over and keep only these:
  - `foundation`: the JS packages line (`frontend/package.json` with spa); dependencies (convention 6); Verification
    Scripts (E2E only); Frontend Bundling (the stack's dev server, and `frontend/` with spa);
  - `laravel/core`: Testing (E2E only);
  - `boost/core`: the worktree database line under Tools; Project Rules (the `CLAUDE.md` system, no `@if`); Tinker
    without "prefer tests with factories".
- The others, and what each counters:

| Override | Counters |
|---|---|
| `.ai/guidelines/enforce-tests`, `pest/core` | Boost's unit/feature test guidance |
| `.ai/guidelines/deployments/core` (renders empty) | the Laravel Cloud pointer Boost ≥ 2.10 always adds (`GuidelineComposer::getCoreGuidelines()`) |
| `config/boost.php` | Claude Code's guidelines going to `AGENTS.md` (`src/Install/Agents/ClaudeCode.php`); `rules.enabled` (the `record-rule` tool and `.ai/rules`); with spa, `browser_logs_watcher` (its logger has no Laravel page to inject into) |
| `.ai/skills/testing-best-practices` | Boost's testing skill; `.ai/skills/` is merged last, keyed by `name` |
| `.ai/skills/infer-conventions` | Boost's convention sweep, which records into `.ai/rules`, as would any new core skill that does |
| `.ai/skills/tailwindcss-development` (spa) | Roster reading only the root lockfile, so Boost never picks the Tailwind skill for `frontend/`. The project copies it from `vendor/laravel/boost/.ai/tailwindcss/4/skill/` (`frontend/CLAUDE.md`, First frontend change) and diffs it after an upgrade |
