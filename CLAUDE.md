# CLAUDE.md — petar-spasic/laravel-kanban

A public, dev-only Laravel package that provides:
- a git-backed kanban board, stored on an orphan `kanban` branch checked out at `docs/kanban`;
- the `vendor/bin/kanban` CLI;
- a local `/kanban` UI;
- Claude Code hooks and agents;
- one Docker stack per git worktree.

Consumers read `README.md` and the Boost skill (`resources/boost/skills/kanban`). This file is for working **on** the package.

## Rules

1. Be concise; comment only logic that can't be inferred from the code. No provenance anywhere ("was X, now Y", "taken from …"): files state what is; history lives in git.
2. Commits carry no Co-Authored trailer. `githooks/commit-msg` rejects one, and this repo uses it (`git config core.hooksPath githooks`).
3. YAGNI and KISS.
4. **Dependencies:**
   - require: `php` and `laravel/framework` 12|13 only.
   - require-dev: `orchestra/testbench`, `pestphp/pest`, `laravel/pint`.
   - Nothing else without the owner's approval: no JSON-schema, Markdown or JS libraries.
5. **Tests are E2E only.** They run real entry points against temp git repos: `bin/kanban`, `bin/kanban-guard` and the git hooks through Process, HTTP through testbench routes, and hook payloads on stdin. Fake only outside systems: docker (`tests/Support/FakeDocker`) and remotes (a local bare repo).
6. **Fixtures use invented names** (key ACME, "Acme Notes"), never a real project's names, ports or text. **Tests never hardcode machine addresses.** Hosts come from the environment (`CodeSandbox::lanHost()` reads `LOCAL_APP_URL`); network ranges in fixtures come from `198.18.0.0/15`, because the doctor counts the host's own interfaces. Docs use RFC 5737 examples.
7. **Record gotchas in the same session,** as symptom → cause → fix: what consumers meet goes into `resources/boost/skills/kanban/references/gotchas.md` (the only gotchas file), what only package work meets goes into this file. Remove an entry once the code fixes it.

## Load-bearing constraints

- **`bin/kanban-guard` + `src/Guard/*` have zero dependencies.** They are loaded with `require_once` and use no Composer or Illuminate. The guard runs on every tool call, so p95 must stay under 50 ms; `tests/E2E/Guard` asserts this.
  - Subagents fail **closed**: an internal error means a deny.
  - The main session fails **open**: no output.
- **`bin/kanban` never boots the host app.** It is Illuminate Console standalone: `.env` via Dotenv, `config/kanban.php` merged over the package config. Hooks and workers depend on this when a branch breaks the app. The same command classes run under artisan (`kanban:*`); only `kanban:install` is artisan-only (`KanbanServiceProvider::ARTISAN_COMMANDS`).
- **Commands are discovered,** not listed: every `src/Console/*Command.php` extending `Console\Command` (implement `perform(): int`).
- **Board writes go only through `Store`** (`Store\Git\GitStore`). Each write takes a flock on `.git/laravel-kanban/lock`, checks the rev, validates, writes atomically in canonical JSON, and makes one commit.
  - In the container the UI commits with `--git-dir/--work-tree`; when git is unusable it falls back to `journal.jsonl`.
  - Never write board files any other way.
- **Stage transitions go only through `Policy\Transitions`.** start, apply, sendBack, finish and stop are unreachable from `move`.
- **Reports and verdicts are staged by the CLI** (`report`, `verdict`) and applied at SubagentStop: the hook payload's last message is not the subagent's report.
- **Stack commands strip the host env.** `Code\Stack` removes main's `.env` keys and every `COMPOSE_*` from docker's environment, passes `-p`, and asserts `config .name`. Otherwise a worktree stack takes over the main one.
- **Branches:** the board branch is `kanban`, code branches are `card/<id>-<slug>`. `kanban/…` is impossible next to `kanban`.

## Map

| Path | Role |
|---|---|
| `src/Store/**`, `src/Schema/*`, `schema/*.json` | Store contract, git driver (writes, merge driver, sync, claims), validation |
| `src/Policy/*` | Transitions, ready policy, pull order |
| `src/Console/*`, `src/Console/Install/*` | CLI commands; install steps (settings hooks, agents, Boost, .gitignore) |
| `src/Code/*` | Worktrees, worktree `.env`, machine-wide port registry, compose stacks, merge checks |
| `src/Protocol/*`, `src/Hooks/*` | Runtime files, staged reports and verdicts, stop gates, lease, SessionStart brief, context, the 5 hook handlers |
| `src/Guard/*`, `bin/kanban-guard`, `githooks/*` | PreToolUse guard; commit-msg and pre-push hooks |
| `src/Http/*`, `routes/web.php`, `resources/views`, `resources/dist` | Local UI (no build step, vanilla JS, CSP-safe) |
| `src/Import/*` | House docs importer (`docs/decisions.md`, `docs/ideas.md`) |
| `stubs/board`, `stubs/claude` | Board skeleton; hooks JSON and agent definitions written by install |
| `resources/boost/{guidelines,skills}` | What Boost installs into consumers' `CLAUDE.md` and `.claude/skills` |
| `tests/E2E/*`, `tests/Support/*` | E2E suites and sandboxes (`Sandbox`, `Origin`, `CodeSandbox`, `GuardSandbox`, `ProtocolSandbox`, `UiSandbox`) |

## Workflow

- **Check (local only, no CI):** `vendor/bin/pest` (all green) and `vendor/bin/pint --test` before every push. While other agents share the checkout, run pint on your own paths: `pint` and `--dirty` format every untracked file.
- **Release:** semver tag (`git tag -a vX.Y.Z`), push the branch and the tag; consumers upgrade as the README says.
- **Trying it in a consumer:** push a commit and `composer update petar-spasic/laravel-kanban` with the constraint `dev-main`; a path repository (`../laravel-kanban`) does not resolve inside the consumer's container.
