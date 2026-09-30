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
7. **Review agents do not run the tests.** The implementer runs `./dev check` (and `./dev ui` for UI changes) after every change; a reviewer repeating them finds nothing new. Tell each review agent to read and reason, and to run only the narrowest reproduction that confirms a specific finding.
8. **Record gotchas in the same session,** as symptom → cause → fix: what consumers meet goes into `resources/boost/skills/kanban/references/gotchas.md` (the only gotchas file), what only package work meets goes into this file. Remove an entry once the code fixes it.

## Load-bearing constraints

- **`bin/kanban-guard` + `src/Guard/Guard.php` have zero dependencies.** They are loaded with `require_once` and use no Composer or Illuminate. The hook runs on tool calls, so p95 must stay under 50 ms; `tests/E2E/Guard` asserts this. It never allows or denies: it binds an agent to its card at EnterWorktree, refreshes the heartbeat and records kanban spawns. Any error prints nothing. Agents are steered by their instructions (`stubs/claude/agents`, the SubagentStart context), not fenced: do not add deny rules.
- **`bin/kanban` never boots the host app.** It is Illuminate Console standalone: `.env` via Dotenv, `config/kanban.php` merged over the package config. Hooks and workers depend on this when a branch breaks the app. The same command classes run under artisan (`kanban:*`); only `kanban:install` is artisan-only (`KanbanServiceProvider::ARTISAN_COMMANDS`).
- **The UI needs nothing from the host app.** `/kanban` is one Blade shell (`resources/views/app.blade.php`) plus a JSON API under `/kanban/_api` (`_api` is not a slug, so no board can collide with it); `resources/dist/kanban.{js,css}` draw everything. Its routes run without the `web` group (`ui.middleware` defaults to `[]`): no session, cookie or CSRF token; `UiGuard` refuses cross-site requests, foreign Host names (DNS rebinding) and writes without `X-Kanban`; `UiHeaders` sends the CSP (`default-src 'self'`) that the Markdown of card text relies on. No build step, no libraries, no inline script or style, no `innerHTML` except the server-escaped Markdown (`view.innerHTML = html; /* md-sink */`). Every colour, radius and font size is a token in `:root` (`light-dark()` for the two themes; no colour literal outside it), and the coarse-pointer and forced-colours blocks are the last rules of `kanban.css`; icons are inline SVG built from the marked `ICONS` table, never glyphs or `data:` URIs (CSP). Cards open as layered panels (`S.stack`, URL `?from=`), each panel an instance from `buildPanel`; every dropdown is `openList`/`selectPill`. The script has no automated tests (tests are E2E only), so it is checked in a browser under a strict CSP; see Workflow. Reads go through `Http\Presenter`, writes through `Store`/`Transitions` with the card's rev; the shared edit rules live in `Policy\Edits` (including the lock on stages listed in `locked`) and `Policy\Creation` (the CLI uses them too).
- **Commands are discovered,** not listed: every `src/Console/*Command.php` extending `Console\Command` (implement `perform(): int`).
- **Board writes go only through `Store`** (`Store\Git\GitStore`). Each write takes a flock on `.git/laravel-kanban/lock`, checks the rev, validates, writes atomically in canonical JSON, and makes one commit.
  - In the container the UI commits with `--git-dir/--work-tree`; when git is unusable it falls back to `journal.jsonl`.
  - Never write board files any other way.
- **Sync has one primitive, `GitStore::sync()`:** fetch (no lock), rebase with the merge driver under the write lock, push (no lock). Every trigger ends there: the debounced runner after a write and the timed pull `maybeSync()` (UI poll, SessionStart, `next`, `status`; one ask per `pull_seconds`, shared through `sync.tick`) single-flight through `sync.lock`; the CLI (`sync`, `publish`, install) runs it directly, and a push race with a runner is settled by the loser fetching again. The rebase passes the running package's own `bin/kanban` as the driver, never the path in `.git/config` (a container cannot run the host's). A claim is won only by the push that lands: each round fetches, rebases, re-checks the card and capacity on what origin holds, commits and pushes once; a rejected push drops that commit and starts over. Unpushed commits are never a reason not to pull. `SyncStatus` (`sync.status.json`) is advisory: the UI, `status` and the brief read it, and no claim, write or merge decides from it. The container syncs over ssh with the clone's own key, `.git/laravel-kanban/deploy_key` (`Store\Git\DeployKey`, made by `configure()`: install, attach, `doctor --fix`; ssh origin plus a local compose file only); the compose file points the container at it with `GIT_SSH_COMMAND`, and nothing points this machine's git at it (`core.sshCommand` would break a user whose own key works until an admin adds the deploy key).
- **The merge driver is a pure function of (ancestor, A, B, path)** (no clock, randomness or I/O), so two machines merging the same conflict write the same bytes. What the newer side displaced (texts, a stage/claim/work state, a cleared block) goes into the card log as `conflict` entries with content-derived ids, which the log union writes once. Keep the package version the same on every clone.
- **`who` is for display.** A log entry's `by` stays one of the six roles; the optional `who` (`KANBAN_USER`, else git `user.name`, else an explicit `KANBAN_GIT_AUTHOR`) is shown beside it and never decides a claim, note, lock or merge. One UI server is one person.
- **A text editor in the page settles a 409 by content, never by the newer rev alone:** the field still says what the editor started from -> save again without asking; already what was typed -> nothing to write; anything else -> show the other version beside the typed one and let the user choose. Drafts keep the text and revision they were typed against.
- **Stage transitions go only through `Policy\Transitions`.** start, apply, sendBack, finish and stop are unreachable from `move`.
- **Reports and verdicts are staged by the CLI** (`report`, `verdict`) and applied at SubagentStop: the hook payload's last message is not the subagent's report.
- **Stack commands strip the host env.** `Code\Stack` removes main's `.env` keys and every `COMPOSE_*` from docker's environment, passes `-p`, and asserts `config .name`. Otherwise a worktree stack takes over the main one.
- **Branches:** the board branch is `kanban`, code branches are `card/<id>-<slug>`. `kanban/…` is impossible next to `kanban`.

## Map

| Path | Role |
|---|---|
| `src/Store/**`, `src/Schema/*`, `schema/*.json` | Store contract, git driver (writes, merge driver, sync and its status, claims), validation |
| `src/Policy/*` | Transitions, ready policy, pull order |
| `src/Console/*`, `src/Console/Install/*` | CLI commands; install steps (settings hooks, agents, Boost, .gitignore) |
| `src/Code/*` | Worktrees, worktree `.env`, machine-wide port registry, compose stacks, merge checks |
| `src/Protocol/*`, `src/Hooks/*` | Runtime files, staged reports and verdicts, stop gates, lease, SessionStart brief, context, the 5 hook handlers |
| `src/Guard/Guard.php`, `bin/kanban-guard`, `githooks/*` | PreToolUse binder (binding, heartbeat, spawn record); commit-msg and pre-push hooks |
| `src/Http/*`, `routes/web.php`, `resources/views`, `resources/dist` | Local UI: shell page, JSON API, `Presenter`, `UiGuard`; `kanban.js`/`kanban.css` (no build step, vanilla JS, CSP-safe) |
| `src/Import/*` | House docs importer (`docs/decisions.md`, `docs/ideas.md`) |
| `stubs/board`, `stubs/claude` | Board skeleton; hooks JSON and agent definitions written by install |
| `resources/boost/{guidelines,skills}` | What Boost installs into consumers' `CLAUDE.md` and `.claude/skills` |
| `dev`, `docker/*` | The dev container: Dockerfile, `./dev`, the browser checks of the UI (`docker/browser`), and the build of the bundled font (`docker/fonts`) |
| `tests/E2E/*`, `tests/Support/*` | E2E suites and sandboxes (`Sandbox`, `Origin`, `CodeSandbox`, `GuardSandbox`, `ProtocolSandbox`, `UiSandbox`) |

## Workflow

- **Container:** `./dev` (`docker/Dockerfile`) is the one environment: PHP 8.5 (`PHP_VERSION=8.3 ./dev build` for the oldest supported), git 2.47 (the package needs ≥ 2.42, so the base is Debian trixie), Composer, and headless Chromium with Playwright's library. `vendor` lives in a named volume, so the host's PHP never matters; files written into the checkout belong to you. Nothing else is needed on the host but Docker. `./dev` alone lists the commands. Node, Chromium and `playwright-core` exist only in the image: they are not package dependencies (rule 4).
- **The font:** Inter ships as three subset files (`resources/dist/inter-<revision>-<weight>.woff2`, OFL text beside them), built by `./dev fonts` from the image. They are served `immutable` without a version query, so a changed file needs a new revision number: `revision=` in `docker/fonts/build.sh`, then the names in `kanban.css`, `Ui::ASSETS` and `app.blade.php` (the tests read the file names from the directory, so a missed one fails them).
- **Check (local only, no CI):** `./dev check` before every push: the suite in parallel (about 30 s; the tests use their own temp dirs and random port pools) and `pint --test`, inside the container above. While other agents share the checkout, run pint on your own paths: `pint` and `--dirty` format every untracked file.
- **UI changes:** the script has no unit tests, so `./dev ui` drives it in a real Chromium (CSP `default-src 'self'`) against a seeded board: `docker/browser/checks/*.mjs`, one file per area, screenshots in `build/ui`. A new interaction gets a check there (`t.ok`), written first and seen failing on the old code, because a check that passes before the fix proves nothing; the accessibility sweep (`a11y.mjs`) holds contrast (4.5:1), target size (24 px), names, tab order and, on a touch device, 16 px text boxes and 40 px controls; `forced.mjs` emulates Windows high contrast; `./dev ui serve` serves the seeded board at http://localhost:8099/kanban for looking at by hand. A check that needs another person opts into the `team` seed (`export const seed = 'team'`): an origin, the page's server running as Ana with sync on, and a second clone "peer" (Ben) that `t.cli(cmd, { root: t.seed.peer, env })` acts in.
- **Release:** semver tag (`git tag -a vX.Y.Z`), push the branch and the tag; consumers upgrade as the README says.
- **Trying it in a consumer:** push a commit and `composer update petar-spasic/laravel-kanban` with the constraint `dev-main`; a path repository (`../laravel-kanban`) does not resolve inside the consumer's container.
