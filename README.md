# laravel-kanban

A git-backed kanban board, a worktree-per-card workflow with its own Docker stack, and the Claude Code hooks, agents
and guard that let a main session run parallel agents safely. Dev-only, for Laravel projects.

- **The board is the plan of record.** JSON files on an orphan branch `kanban`, checked out at `docs/kanban`. Every
  change is one commit, made by the CLI, the local UI or a hook — never by hand.
- **One card = one worktree = one stack.** `kanban start` claims a card, creates `.claude/worktrees/xxxxxx-<slug>` on its
  own branch, writes a `.env` with its own ports and brings up its own compose project. The worktree, the compose
  project, its containers and the agent's description carry the card's id and title, so what is being worked on reads
  at a glance in `docker ps` and the agent list.
- **Agents are fenced.** A PreToolUse guard and git hooks let a subagent commit only inside its own worktree;
  push, pull, reset, checkout, worktree commands and board edits are the main session's.
- **Done is proven.** A skeptical, read-only evaluator verifies every acceptance criterion; `finish` merges only an
  approved head; `publish` pushes once per run.

## Requirements

- PHP 8.3+, Laravel 12 or 13
- git ≥ 2.42 (`git worktree add --orphan`)
- Docker Compose v2 for worktree stacks (optional: without a compose file cards get a worktree only)
- Claude Code for the agent workflow; Laravel Boost optional (it installs the guideline and the `kanban` skill)

## Install

```bash
composer require --dev petar-spasic/laravel-kanban:^0.1
php artisan kanban:install --key=KEY       # --dry-run prints what would change
vendor/bin/kanban doctor                   # ok|warn|fail per check, exit 1 on any fail
```

Then restart Claude Code (agents and hooks load at session start), and run `vendor/bin/kanban lease --takeover` if the
old session holds the orchestrator lease.

`kanban:install` (main checkout, once per project):

1. creates the orphan branch `kanban` at `docs/kanban` with a stub board and commits it;
2. configures this machine: the JSON merge driver, `core.hooksPath` → the package's `githooks/`, and
   `kanban.rejectCoAuthored` from `githooks.reject_co_authored`;
3. merges `.claude/settings.json` (hooks below, `permissions.allow` += `Bash(vendor/bin/kanban *)`, and commit and PR
   attribution off while Co-Authored-By trailers are rejected); foreign hooks are kept;
4. writes `.claude/agents/kanban-worker.md` and `kanban-evaluator.md` (only files carrying its marker are overwritten),
   with `model`/`effort` from `kanban.agents`;
5. with `boost.json`: adds `petar-spasic/laravel-kanban` to `packages` and runs `boost:update` (guideline into
   `CLAUDE.md`, skill into `.claude/skills/kanban`); without Boost: a `<!-- laravel-kanban:start/end -->` block in
   `CLAUDE.md`;
6. adds `/docs/kanban/` and `/.claude/worktrees` to `.gitignore`.

Everything on the main branch is left for you to review and commit. Every other machine or clone:
`composer install && vendor/bin/kanban attach`.

**Upgrade:** `composer update petar-spasic/laravel-kanban`, `vendor/bin/kanban doctor --fix` (agents, hooks, git config),
`php artisan boost:update` (with Boost), then restart Claude Code.

## The board

```
docs/kanban/kanban.json                   {"version":1,"key":"KEY","id_length":6,"max_parallel":6,"ready_buffer":12,
                                           "wip":{"review":6},"stale_after_minutes":20,"guard":{"strict":false,"main_write_paths":[]}}
docs/kanban/<epic>/epic.json              {"title","goal","done_when":[…],"body","order"}
docs/kanban/<epic>/<board>/board.json     {"title","kind":"work|decisions","body","order","wip":{"doing":6}}
docs/kanban/<epic>/<board>/KEY-XXXXXX.json
```

```json
{ "id": "KEY-XXXXXX", "type": "feature", "title": "Export invoices as CSV", "stage": "ready", "priority": "high",
  "labels": ["area:billing"], "body": "## Context\n…",
  "acceptance": [{ "id": 1, "text": "GET /invoices.csv lists the month's invoices", "done": false }],
  "depends_on": ["KEY-YYYYYY"], "blocked": null, "claim": null, "work": null,
  "created": "2026-09-28T19:00:00.000+00:00", "updated": "…", "log": [] }
```

- **Stages** — work boards: `backlog → ready → doing → review → done` (+ `dropped`); decision boards:
  `proposed → decided → superseded` (+ `dropped`). Decided cards are binding.
- **Types** `feature|bug|chore|spike|decision`; **priority** `urgent|high|normal|low` (`urgent` may exceed capacity by 1).
- **IDs** `KEY-` + 6 Crockford base32 characters, random; any unique prefix of ≥ 3 characters is accepted.
- **Rules** — unknown keys are errors; 1–12 acceptance criteria to enter ready; `depends_on` acyclic; `claim`/`work`
  only in doing/review; `log` append-only. `kanban validate [--fix]` checks all of it.
- Files are canonical pretty JSON with a field-group-aware merge driver, so two machines editing the board converge.

## Workflow

```
owner ── /kanban UI (main stack, local only) ─┐
main session ── vendor/bin/kanban … ──────────┼──> docs/kanban (branch kanban, one commit per write)
hooks (SessionStart, SubagentStart/Stop, …) ──┘
  │ kanban start KEY-XXXXXX        claim · worktree · .env · port slot · compose up -d
  ▼
.claude/worktrees/xxxxxx-<slug>   branch card/key-xxxxxx-<slug>   project {{app}}-wt-xxxxxx-<slug>   ports 2101x
  ▲ spawned with isolation: worktree → WorktreeCreate hands it this worktree; EnterWorktree(path) → the guard binds it
kanban-worker (background): commits on its branch → kanban report … (staged)
  │ SubagentStop: clean tree, ≥ 1 commit, gates.report → applied → stage review
kanban refresh (merge main into the branch) → kanban-evaluator (read-only) → kanban verdict …
  │ approve → kanban finish: merge --no-ff · done · stack down · slot freed · worktree + branch removed
  ▼ end of run: kanban publish → git push origin kanban main
```

The main session follows the `kanban` skill (run loop, decisions, recovery). Every command, flag, exit code and
transition is in [`references/protocol.md`](resources/boost/skills/kanban/references/protocol.md);
`vendor/bin/kanban <cmd>` runs without booting the app, and the same classes run as `php artisan kanban:<cmd>`. In a
code worktree it runs the main checkout's copy: the worktree's `vendor/` dates from its card's start.

## Hooks

Merged into `.claude/settings.json` (exec form, so paths need no quoting):

| Event | Handler | Does |
|---|---|---|
| SessionStart | `kanban hook session-start` | attach if missing, flush, retry the inbox, export `KANBAN_SESSION`, print the brief |
| SubagentStart | `kanban hook subagent-start` | register kanban agents, add the board rules to their context |
| SubagentStop (`kanban-worker\|kanban-evaluator`) | `kanban hook subagent-stop` | refuse a stop without a report, run the gates, apply the report or verdict |
| PreToolUse (`Bash\|Monitor\|Edit\|Write\|NotebookEdit\|EnterWorktree\|ExitWorktree\|Agent`) | `bin/kanban-guard` | plain PHP, < 50 ms; binds agents to cards, denies writes outside the own worktree, board edits and non-own git |
| WorktreeCreate / WorktreeRemove | `kanban hook worktree-create\|remove` | a kanban agent the guard saw spawned gets its card's worktree; `claude -w <ID>` too; anything else a fresh worktree with deps, `.env` and a port slot. Card worktrees are never removed by the hook |

Git hooks (`core.hooksPath`): `commit-msg` rejects `Co-Authored-By` trailers unless `githooks.reject_co_authored` is
off; `pre-push` rejects pushes from a code worktree.

### Agent isolation

Both agents carry `isolation: worktree`. The guard records each kanban spawn (`.git/laravel-kanban/spawns/<ID>.json`)
and the WorktreeCreate hook hands the isolated subagent the worktree of the oldest record (≤ 2 min old), so it starts
pinned there; two isolated spawns in one message could swap worktrees, which the agent's first `EnterWorktree(path)`
corrects. When Claude Code refuses `EnterWorktree`, the guard has bound the agent anyway (**guard-only mode**: absolute
paths, `cd <worktree> && …`, writes outside its worktree denied).

## Worktree stacks

Each worktree runs the project's own `docker-compose.local.yml` (`stack.compose_file`) under its own project name,
with ports from a machine-wide registry (`~/.local/state/laravel-kanban/stacks.json`, slots 1–99 × 10 ports from 21000):

```dotenv
# written into .claude/worktrees/xxxxxx-<slug>/.env (main's .env minus these keys)
COMPOSE_PROJECT_NAME={{app}}-wt-xxxxxx-<slug>
WEB_PORT=21010
DB_HOST_PORT=21011
REDIS_HOST_PORT=21012
DB_PORT=21011            # host artisan and tests hit this stack
SIDECAR_BIND=127.0.0.1
LOCAL_APP_URL=http://203.0.113.10:21010
SESSION_COOKIE={{app}}-wt-xxxxxx-<slug>-session
```

The compose file must work for many stacks at once:

```yaml
name: "${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME unset - main: add it to .env; worktree: vendor/bin/kanban stack create}"
services:
  app:
    build: .                                          # no image: on a built service
    ports: ["${WEB_PORT:-{{web_port}}}:80"]           # every published host port from a stack.ports variable
  postgres:
    image: postgres:16-alpine
    ports: ["${SIDECAR_BIND:-0.0.0.0}:${DB_HOST_PORT:-{{db_port}}}:5432"]
# never: container_name, a volume or network name: not derived from ${COMPOSE_PROJECT_NAME}
```

- `doctor` fails on `container_name`, a fixed host port, a volume or network `name:` not derived from
  `${COMPOSE_PROJECT_NAME}`, `image:` on a built service, a top-level `name:` without `${COMPOSE_PROJECT_NAME:?…}`,
  and a main `.env` without `COMPOSE_PROJECT_NAME` (`{{app}}-local`);
- `doctor` warns when `phpunit.xml` sets `DB_HOST`/`DB_PORT` (tests in a worktree would hit main's database) and on
  `external` volumes with a fixed name in the other `docker-compose*.yml` files, which miss the project-scoped local
  volumes (`<project>_<volume>`);
- main's ports stay outside 21000–21999; Vite must not watch `.claude/worktrees` and `docs`.

### Docker address pools

Every stack is one Docker network. When the host's LAN overlaps Docker's default address pools, only a few networks
stay free, about 6 stacks machine-wide. Widen the pools once (sudo), then bring the stacks back up (compose stacks
without a restart policy stay down):

```json
// /etc/docker/daemon.json
{ "bip": "172.17.0.1/16",
  "default-address-pools": [ { "base": "10.210.0.0/16", "size": 24 }, { "base": "172.16.0.0/12", "size": 16 } ] }
```

```bash
sudo systemctl restart docker
vendor/bin/kanban doctor        # "docker address pools: N free networks" (warns below 6)
```

`stack.max_stacks` (default 6, `KANBAN_MAX_STACKS`) caps stacks per machine; before each `up` the registry also
requires MemAvailable ≥ 8 GiB, ≥ 20 GiB free on `/` and a 1-minute load < 0.75 × CPUs.

## Team sync

- `sync=off` (default, one developer): board commits stay local until `kanban publish` pushes `kanban` and `main`.
- `KANBAN_SYNC=on` (teams): every write pulls first and pushes after; a claim is won only by the push that lands
  (the loser exits 8). Rejected pushes rebase and retry 3×, then exit 9; nothing is ever force-pushed.
- Each machine runs `vendor/bin/kanban attach` once (SessionStart does it when `docs/kanban` is missing); it also
  configures the merge driver, without which git would silently text-merge board files.
- Only one orchestrating session per machine holds the lease (15 min idle expiry, `kanban lease --takeover`).

## Configuration

`php artisan vendor:publish --tag=kanban-config` → `config/kanban.php`: `ui.*` (the local `/kanban` UI, local env and
main checkout only), `agents.worker|evaluator.model|effort` (written into the agent files), `main_branch`, `remote`,
`sync`, `worktrees.*`, `stack.*` (compose file, project pattern, port pool, env block, caps), `finish.after` (commands
run on main after a merge), `gates.report` (commands a worker's branch must pass, e.g. `npm run check`; `context`
prints them for both agents), `githooks.reject_co_authored` (default `true`).

## Gotchas

Surprises met in real runs are in
[`references/gotchas.md`](resources/boost/skills/kanban/references/gotchas.md) as symptom → cause → fix.

## License

MIT
