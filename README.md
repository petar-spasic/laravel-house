# laravel-kanban

A git-backed kanban board, a worktree-per-card workflow with its own Docker stack, and the Claude Code hooks and agents
that let a main session run parallel agents safely. Dev-only, for Laravel projects.

- **The board is the plan of record.** JSON files on an orphan branch `kanban`, checked out at `docs/kanban`. Every
  change is one commit, made by the CLI, the local UI or a hook — never by hand.
- **One card = one worktree = one stack.** `kanban start` claims a card, creates `.claude/worktrees/xxxxxx-<slug>` on its
  own branch, writes a `.env` with its own ports and brings up its own compose project. The worktree, the compose
  project, its containers and the agent's description carry the card's id and title, so what is being worked on reads
  at a glance in `docker ps` and the agent list.
- **Agents follow short rules.** Their instructions say a subagent commits only inside its own worktree;
  push, pull, reset, checkout, worktree commands and board edits are the main session's. Git hooks reject
  `Co-Authored-By` trailers and pushes from a code worktree.
- **Done is proven.** A skeptical, read-only evaluator verifies every acceptance criterion; `finish` merges only an
  approved head; `publish` pushes once per run.

## Requirements

- PHP 8.3+, Laravel 12 or 13
- git ≥ 2.42 (`git worktree add --orphan`)
- Docker Compose v2 for worktree stacks (optional: without a compose file cards get a worktree only)
- Claude Code for the agent workflow; Laravel Boost optional (it installs the guideline and the `kanban` skill)

## Install

```bash
composer require --dev petar-spasic/laravel-kanban
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

With the default `auto`, a project with an `origin` publishes its board to it on the first write (`kanban:install` and `attach` do so at once). Set `KANBAN_SYNC=off` in `.env` first to keep a board on this machine; a `config/kanban.php` published earlier keeps `sync` off until its line is changed (see Team sync). Keep the package version the same on every clone of a shared board.

## The board

```
docs/kanban/kanban.json                   {"version":1,"key":"KEY","id_length":6,"max_parallel":6,"ready_buffer":12,
                                           "wip":{"review":6},"stale_after_minutes":20,
                                           "locked":["doing","review","done","superseded"]}
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
- **Locked stages** — a card in a stage listed in `locked` (default `doing`, `review`, `done`, `superseded`; `[]` locks
  nothing) can only be annotated by the owner and the main session: `note=`, `blocked=` and `tick=`/`untick=` change it,
  a title, description, labels, dependencies, priority, type or criterion does not (exit 3, and the UI shows the card
  read-only). The work is done from the card as an agent and the evaluator read it, so it is not rewritten under them. To
  edit a card in `doing` or `review`, put it back first (`kanban stop ID --to=ready`); the main session can override any
  locked stage with `kanban set … --force`, which is the only way to change a `done` or `superseded` card.
  Moving a card to another board is refused the same way; moves between stages follow the transition table, locked or not.
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
  ▲ spawned with isolation: worktree → WorktreeCreate hands it this worktree; EnterWorktree(path) → the PreToolUse hook binds it
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
| SessionStart | `kanban hook session-start` | attach if missing, export `KANBAN_SESSION`, flush, retry the inbox, print the brief; then tidy up: prune stopped agents (14 days), applied reports (7 days), unclaimed spawns, staged files of finished cards, an expired lease and dependency-copy leftovers, and start `sweep --reclaim` in the background, which removes clean isolated-agent worktrees (up to ten per run) (`agent-a…`) idle for a day, freeing their slots |
| SubagentStart | `kanban hook subagent-start` | register kanban agents, add the board rules to their context |
| SubagentStop (`kanban-worker\|kanban-evaluator`) | `kanban hook subagent-stop` | refuse a stop without a report, run the gates, apply the report or verdict |
| PreToolUse (`Bash\|Monitor\|Edit\|Write\|NotebookEdit\|EnterWorktree\|Agent`) | `bin/kanban-guard` | plain PHP, < 50 ms, never denies: binds an agent to its card at `EnterWorktree`, refreshes its heartbeat, records the spawn of a kanban agent |
| WorktreeCreate / WorktreeRemove | `kanban hook worktree-create\|remove` | a kanban agent spawned from the main session gets its card's worktree; `claude -w <ID>` too; anything else a fresh worktree with deps, `.env` and a port slot. Card worktrees are never removed by the hook |

Git hooks (`core.hooksPath`): `commit-msg` rejects `Co-Authored-By` trailers unless `githooks.reject_co_authored` is
off; `pre-push` rejects pushes from a code worktree.

### Agent isolation

Both agents carry `isolation: worktree`. The PreToolUse hook records each kanban spawn (`.git/laravel-kanban/spawns/<ID>.json`)
and the WorktreeCreate hook hands the isolated subagent the worktree of the oldest record (≤ 2 min old), so it starts
pinned there; two isolated spawns in one message could swap worktrees, which the agent's first `EnterWorktree(path)`
corrects. When Claude Code refuses `EnterWorktree`, the hook has bound the agent anyway: it then uses absolute
paths and `cd <worktree> && …`.

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
requires MemAvailable ≥ 8 GiB, ≥ 20 GiB free on `/` and a 1-minute load < 0.75 × CPUs (the load check is skipped where the CPU count is unknown, e.g. macOS).

## Team sync

- `KANBAN_SYNC=auto` (the default): sync is on while the project has a remote (`origin`). `kanban:install` next to one publishes the
  board once and says so; a teammate who runs `attach` joins it; with no remote nothing is pushed or asked for. `on` (also `1`,
  `true`, `yes`) does the same without looking for a remote; `off` (or any value it does not know) keeps every board commit on this
  machine until `kanban publish` pushes `kanban` and `main`. A published `config/kanban.php` whose line reads `'sync' => env('KANBAN_SYNC', 'off')` still wins
  over that default: change it to `'auto'` or delete it. `status` and the SessionStart brief show the effective state (`sync auto (on)`), and warn
  when the board is published but sync is off here (claims are then not coordinated with other machines).
- With sync on, writes are pushed after and `sync` pulls; a claim pulls first, is checked
  (blocked, dependencies, area, WIP) against what it pulled, and is won only by the push that lands (the loser exits 8). A claim whose push is
  rejected starts over on the fresh board, so it is checked again against the claim that landed first; after 3 rounds it exits 9 with no claim
  left behind. Rejected sync pushes rebase and retry 3×, then exit 9; nothing is ever force-pushed. A staged worker report is not applied over
  a card that has since moved on or is held on another machine: it stays staged.
- The board runs ahead of `main`: `finish` marks a card done for everyone at once, but its code reaches the others only when
  `kanban publish` pushes `main` (merging `origin/main` when it moved). A dependent card started before that branches from code that
  lacks its dependency, so with a shared board the owner decides when `publish` runs.
- An idle clone pulls too: whenever the UI is polled (every 3 s while a tab is visible), a Claude Code session starts, or `status` or `next`
  runs, a background sync is asked for if none was within `pull_seconds` (`KANBAN_PULL_SECONDS`, default 30, at least 5; `0` = only
  after your own writes). So another person's change shows on an open board within a poll plus that interval. A clone whose own push
  keeps failing still pulls. In a container the sync runs there: it needs `php` and `git` on the PATH, `exec()` allowed, credentials for the
  remote, and a user that can write the mounted `.git`. For an ssh `origin` and a local compose file, `install`, `attach` and
  `doctor --fix` create a deploy key, `.git/laravel-kanban/deploy_key` (never committed, one per clone), and print its public half:
  a repository admin adds it with write access (`gh repo deploy-key add .git/laravel-kanban/deploy_key.pub --allow-write`). The
  container reaches the file through its `./:/app` mount and is pointed at it with `GIT_SSH_COMMAND` in its compose file; this
  machine's own git keeps using its own key. An https `origin` needs a credential helper of the container's own.
- Each machine runs `vendor/bin/kanban attach` once (SessionStart does it when `docs/kanban` is missing); it also
  configures the merge driver, without which git would silently text-merge board files.
- When two clones changed the same field of a card, the newer `updated` wins and the merge writes what the other side had into
  the card's log (`kanban show`, the *Activity* list: *merge kept the other version of body*, with the replaced text folded
  under it), so nothing is lost silently. This needs the same package version on every clone.
- The log says who did what: `by` is the role (owner, main, worker, …) and `who` the person, from `KANBAN_USER` in `.env`, else git's
  `user.name`. The page shows *Ana* for what she did herself and *worker (Ana)* for her agents; `show` prints `owner (Ana)`. One UI
  server is one person; the name is only shown, never used to decide anything. Set `KANBAN_USER` for a container that has no git identity.
- Each clone keeps the result of its last sync (`.git/laravel-kanban/sync.status.json`). `status` and the SessionStart brief print
  `last sync failed (2 in a row): …` while it is failing, and the UI shows a notice (*Not pushed: 3 commits (…). Behind by 2.*)
  once the same failure repeats, at once for a pull that leaves the board invalid, a rebase conflict or a rejected push; a successful
  sync clears it. `doctor` prints the same state.
- Only one orchestrating session per machine holds the lease (15 min idle expiry, `kanban lease --takeover`).

## The board UI

`/kanban` (local environment, main checkout only) is a single page drawn by `resources/dist/kanban.js` from a JSON API
under `/kanban/_api`. It needs nothing from the app it lives in: no session, cookie, CSRF token, `web` middleware
group, layout, Vite or Tailwind, and it sends its own Content-Security-Policy, so it loads nothing from other origins.

- **Board:** one column per stage, cards in pull order, WIP counts, live worker state, and updates within a poll
  (`ui.poll_ms`, 3 s) of any change, from the CLI or another tab, without losing scroll or what you are typing. A card
  shows only what needs attention as a tag: *Blocked*, *Waits on N*, *Working*, *Stale*; priority is a mark, the rest is quiet text.
- **Find:** `/` searches. The chips under the bar count what needs attention (*Blocked*, *Waiting*, *Working*) and filter
  with one click; *Priority*, *Type* and *Label* are dropdowns. Search and filters live in the URL.
- **Move:** drag a card (the lanes that take it light up), or use its **⋯** button or `m`; dropping on `dropped` asks for a
  reason. `doing`, `review` and `done` stay with the CLI (`start`, a worker report, `finish`).
- **Edit:** click a card. A card opens with the focus on the card itself, not in a field: the first `Tab` moves into its name and on through the fields. The name is a field, and title, description,
  priority, type, blocked reason, labels, dependencies and acceptance criteria save as you go (*Saved* shows in the header).
  A card that is open and changes under you says so in one line at the top for a minute (*Ben added a note*, *Ana changed body*, *worker
  (Ana) changed body* when it is the text you are editing); an agent's housekeeping on the rest of the card stays quiet, and so do your own writes.
  Somebody else changing another part of the card never interrupts what you type. If the same text changed while you were typing
  (a description, a why, a blocked reason), your text stays in the editor with the latest version beside it and you choose
  *Keep mine* or *Use theirs*; a name or a criterion changed elsewhere is shown, and yours is kept in the message or the box.
  Type a criterion or label and press `Enter` to add it; a card can have up to 12 criteria and 10 labels. Notes and the
  history sit below. **New** or `n` adds a card. A card in a locked stage (see *Locked stages*) shows as read-only, with a line saying why;
  it still takes a note, a block and ticks.
- **Cards link to cards:** a dependency, a decision it supersedes or is superseded by opens on top of the card you are
  reading, to its right, at any depth; the cards under it peek out as strips (click one to return to it). `Alt+W` or `Esc`
  closes the last one opened. The stack is in the URL, so Back, reload and a shared link keep it. From 1400 px wide the
  cards sit beside the board, which keeps its room; on a narrower window they slide in over it, and on a phone each fills the screen.
- **Dropdowns:** every dropdown is one list with the arrows, `Enter` and type-ahead; a list of more than five options has a
  search box.
- **Quick boards:** `Shift+1`…`9` puts the board you are on in that slot and `Alt+1`…`9` goes to it from anywhere; the slots sit in
  the middle of the bar (they are kept in this browser). The same `Shift+` key again takes the board off.
- **Keys:** `j k h l` move, `Enter` opens, `b` opens the board switcher (a digit picks the board with that number, `b` again: all boards), `m` moves, `p` cycles priority (a card in a locked stage says it cannot), `t` switches between light
  and dark (it follows the system until you switch), `?` lists them all.

The page needs a browser from 2024 or later (it uses `light-dark()`), and sets itself in Inter, shipped with the package
under the SIL Open Font License (`resources/dist/inter-LICENSE.txt`).

A write carries the card's revision; if the card changed since, the UI shows the latest version instead of
overwriting it. To script against the API, send `X-Kanban: 1` with every write (see the gotchas).

## Configuration

`php artisan vendor:publish --tag=kanban-config` → `config/kanban.php`: `ui.*` (the local `/kanban` UI, local env and
main checkout only; `ui.middleware` is extra middleware for its routes, none by default; `ui.hosts` adds names it answers to besides IPs, `localhost`, `*.test` and the `APP_URL` host), `agents.worker|evaluator.model|effort` (written into the agent files), `main_branch`, `remote`,
`sync` (off, auto, on), `pull_seconds` (how often an idle clone asks for a sync), `user` (the name shown beside the role in the log), `worktrees.*`, `stack.*` (compose file, project pattern, port pool, env block, caps), `finish.after` (commands
run on main after a merge), `gates.report` (commands a worker's branch must pass, e.g. `npm run check`; `context`
prints them for both agents), `githooks.reject_co_authored` (default `true`).

## Gotchas

Surprises met in real runs are in
[`references/gotchas.md`](resources/boost/skills/kanban/references/gotchas.md) as symptom → cause → fix.

## License

MIT
