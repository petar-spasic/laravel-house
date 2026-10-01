# Boost

How setup configures Boost (SKILL.md step 7), and how the house overrides are kept current.

## Configure

- `boost.json` holds only three keys before the first install (SKILL.md Gotchas): `"agents": ["claude_code"]`,
  `"cloud": false`, and `"packages"` with `"petar-spasic/laravel-house"`. laravel-kanban adds itself at
  `kanban:install`.
- `composer.json` `post-update-cmd` ends with `@php artisan boost:update --ansi`, so `composer update` refreshes the
  guidelines and skills.
- Run `php artisan boost:install --no-interaction` yourself, never through `!` (SKILL.md Gotchas).
- The project's copy of the house skills is the pinned one. Disable the plugin for the project:
  `claude plugin disable laravel-house@laravel-house --scope project`.

## Check

- The root `CLAUDE.md` ends with the `<laravel-boost-guidelines>` block.
- That block holds none of: "Test every code change", "Unit and feature tests are more important", `make:test`,
  "Laravel Cloud", `composer run dev`.
- There is no `AGENTS.md`, no `.agents/` and no `.claude/skills/deploying-to-cloud`.
- `.claude/skills/testing-best-practices/SKILL.md` is the E2E one ("end to end or not at all").
- `.claude/skills/infer-conventions/SKILL.md` is the stub.

## Overrides

The templates override parts of Boost. Re-check each one after a Boost upgrade.

- `.ai/guidelines/foundation`, `laravel/core` and `boost/core` are Boost 2.10's, with the test lines, the dev-server
  lines, the rules section and the worktree database line changed. After an upgrade, diff them against
  `vendor/laravel/boost/.ai/` and carry the new upstream text over.
- The others, and what each counters:

| Override | Counters |
|---|---|
| `.ai/guidelines/enforce-tests`, `pest/core` | Boost's unit/feature test guidance |
| `.ai/guidelines/deployments/core` (renders empty) | the Laravel Cloud pointer Boost ≥ 2.10 always adds (`GuidelineComposer::getCoreGuidelines()`) |
| `config/boost.php` | Claude Code's guidelines going to `AGENTS.md` (`src/Install/Agents/ClaudeCode.php`) |
| `.ai/skills/testing-best-practices` | Boost's testing skill; `.ai/skills/` is merged last, keyed by `name` |
| `.ai/skills/infer-conventions` | Boost's convention sweep, which records into `.ai/rules`, as would any new core skill that does |
