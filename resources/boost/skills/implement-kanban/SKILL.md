---
name: implement-kanban
description: >-
  Adoption checklist that puts a Laravel project on the kanban board shipped
  with petar-spasic/laravel-house: prerequisites, `kanban:install`, the
  project's decisions archived and its open questions put on cards, a Claude
  Code restart with the lease takeover, the card-ready stack checked by
  `kanban doctor`, a first real card through the agent loop and the first
  publish. Also upgrades a board: consolidating several boards and a decisions
  board into one work board with `fold-boards`, and judging which archived
  decisions become CLAUDE.md rules. Cases: an existing project whose decisions
  live in docs/decisions.md, a new project fresh from laravel-project-setup, a
  board on an older house version, and the separate kanban package. Use when
  the owner types /implement-kanban, to adopt the board or to upgrade it.
  Triggers — implement kanban, adopt the kanban board, upgrade the kanban
  board, fold-boards, board version 1, kanban:install, import-house-docs.
disable-model-invocation: true
---

# Implement the kanban board

This checklist puts a project on the kanban board that ships with petar-spasic/laravel-house, or upgrades it. The rest
is owned elsewhere: the house README ("Adopting the Board" for what `kanban:install` changes, "Worktree Stacks",
"Team Sync") and the `kanban` skill (planning cards, run loop, exit codes, questions, recovery). A defect in the
package met on the way goes upstream (the `kanban` skill, "Package findings").

## Before you start

- Run it from the main session in the main checkout, never in a worktree.
- Ask the owner before anything that touches main's data, needs sudo, pushes, or commits. Commits carry no trailers.
- The install and an upgrade can push the board with no push command. Steps 1 and 2 and "Upgrading a board" say when.

## Choose the path

| Case | You recognise it by | Steps |
|---|---|---|
| (a) An existing project | `docs/decisions.md` (and `docs/ideas.md`) hold its decisions | 1–9 |
| (b) A new project | laravel-project-setup just ran; its choices live only in this chat and the files it wrote | 1–9 |
| (c) A board on an older house version | `docs/kanban` exists, no separate kanban package; the house is behind, or `board version 1` | "Upgrading a board", (c) |
| (d) A board on the separate kanban package | `composer.json` requires the board as a package of its own | "Upgrading a board", (d) |
| (e) The separate package, no board | `composer.json` requires it, but there is no `kanban` branch, local or on `origin` | (e), then (a) or (b) |

A project still on the separate package has an older copy of this skill. The swap in the house README, "Updating the
Composer Package", brings this one; (d) step 2 and (e) are then done.

## 1. Prerequisites: stop and report any that fail

- **The house:** `petar-spasic/laravel-house` in `require-dev` and in `boost.json` `packages`, and no
  `petar-spasic/laravel-kanban` (case (e)). A project without the house comes onto it with laravel-project-setup first.
- **Tools:** Laravel 12 or 13, PHP ≥ 8.3, git ≥ 2.42, Docker Compose v2, Laravel Boost (`boost.json`). `ssh-keygen` on
  the host too, when `origin` is ssh and the compose file below exists.
- **main:** `main` is clean. The install changes files on main. Step 7 commits them.
- **origin and sync:** `origin` decides sync (house README, "Team Sync"). Without one, the board stays on this
  machine. With one, the install publishes the board branch (`kanban`, never `main`) with the host's git credentials
  and pushes every later write. The owner confirms: the repository is private; the user running the install can write to it;
  others share the board, or it stays on this machine (then `KANBAN_SYNC=off` in `.env` before step 2).
  - An empty `origin` (`git ls-remote --heads origin` prints nothing): push `main` first (owner's OK), then install.
    Otherwise the orphan board is pushed first, becomes the forge's default branch, and a clone checks out no code.
  - The URL scheme: with ssh, the container syncs the page with a deploy key. With https, set `KANBAN_GIT_TOKEN` in
    `.env` (fine-grained, read and write on this repository's contents); the local compose's helper uses it. The URL
    in `.git/config` names a host the container resolves: no `~/.ssh/config` alias, no `insteadOf`.
- **The board page:** `/kanban` has no login, and the local stack is LAN-visible by default. The owner chooses
  `KANBAN_UI_TOKEN` or `WEB_BIND=127.0.0.1` in `.env` (laravel-deployment, Trap "Anyone who can reach the web port reads
  and edits the board at `/kanban`").
- **The stack:** a `docker-compose.local.yml`. Without one, cards get a worktree only. Ask whether that is intended.

## 2. Install

```bash
php artisan kanban:install --key=XYZ --dry-run    # read every line
php artisan kanban:install --key=XYZ
```

- `--key` is the card prefix: 2–10 uppercase letters or digits, starting with a letter. The owner picks it.
- `--dry-run` does not show the publish to `origin` (step 1 says when `KANBAN_SYNC=off` must come first).
- Read the output to the end. The `sync:` lines come just before the last line, `next: restart Claude Code`.
- A failed publish prints `sync: … (the board stays on this machine until …)` there and still exits 0. Report it.
- Every clone of a shared board runs the same house version: commit `composer.lock`.
- **Deploy key** (ssh `origin` and the local compose file): the install prints it once, when it makes it. It lives in
  `.git/laravel-house/deploy_key`, never committed; `cat .git/laravel-house/deploy_key.pub` shows it again. Show the
  owner the public half and the settings link (github.com origins only). A repository admin adds it with write access;
  run `gh repo deploy-key add …` only when the owner says so. It is a repository-wide write key the app container can
  read. With no compose file at install, no key is made: step 6 makes it once the file exists.

## 3. Record the decisions, before the restart

**Path (a):**
1. Run `vendor/bin/kanban import-house-docs --dry-run` and read every warning. Then run it without `--dry-run`. Its
   totals line counts what went where: decided and dropped entries into `docs/kanban/decisions.md`, floated ideas as
   backlog spikes carrying their question. Imported plus already imported equals the entries of the two files.
2. Judge the archive (below, "Judging decisions").
3. Delete both files and fix every reference:
   `grep -rn 'decisions\.md\|ideas\.md' --exclude-dir={vendor,node_modules,.claude,kanban} .`

**Path (b):** setup and deployment wrote their choices into the `CLAUDE.md` files already. Every open item becomes a
card with its question, as the `kanban` skill's "Questions and rules" says.

**Both:** in the root `CLAUDE.md`, the decided/open prose becomes a pointer: rules are in the `CLAUDE.md` files, open
questions ride on their cards (`vendor/bin/kanban list --all` shows the blocked ones). "Where the docs live" gets this
row:

```markdown
| `docs/kanban/` (branch `kanban`) | The board: one work board, cards grouped by `area:*`, open questions on their cards; `decisions.md` archives older decisions. Use only `vendor/bin/kanban` |
```

Bugs the owner names become cards on `project/work`, each on an area.

## 4. Restart Claude Code

Run `/exit`, then `claude --continue`. Agents and hooks load only at session start. Restart again after any
`doctor --fix`.
If `vendor/bin/kanban status` shows the pre-restart session holding the lease, run `vendor/bin/kanban lease --takeover`.

## 5. The stack

- **Path (b):** laravel-deployment built it worktree-ready. Step 6 only checks it.
- **Path (a):** bring compose, entrypoints, Caddy, Vite and phpunit to laravel-deployment's shapes ("Many stacks from
  one local compose": its Procedure renders them), and the seeders to laravel-project-setup's seeding standard.
  `doctor --fix` adds the card-stack lines to a local compose file written before them. Dropping `container_name` and
  volume `name:`s renames main's volumes, so the data moves once (about 2 minutes of downtime):

  ```bash
  docker compose -f docker-compose.local.yml down          # the old file: containers go, volumes stay
  # land the new files; main .env += COMPOSE_PROJECT_NAME={{app}}-local
  docker compose -f docker-compose.local.yml up --no-start --build
  docker run --rm --entrypoint sh -v <old postgres volume>:/from:ro -v {{app}}-local_postgres_local:/to postgres:16-alpine -c 'cp -a /from/. /to/'
  docker compose -f docker-compose.local.yml up -d --wait
  ```

  Redis may start empty (cache, queue and sessions only). The old volumes and image go a week later, with the owner.

## 6. Doctor

Run `vendor/bin/kanban doctor` and `vendor/bin/kanban validate` on the host, never in the container (doctor sees host
paths). No `fail` may remain. Read every `warn`:
- `no deploy key …`: `vendor/bin/kanban doctor --fix` makes it and prints the public key on a line of its own.
  The owner registers it (step 2), then restart (step 4). `ok deploy key` only means the file exists, not that the
  repository knows it.
- `sync off but this board is published`: the owner turns sync on (delete `KANBAN_SYNC=off` from `.env`, or the
  pinned `sync` line in `config/kanban.php`), or keeps sync off and accepts that the board lives on this machine only.
- `last sync failed`: stop and report.
- A Docker address-pool `warn` is the owner's sudo step.

**The main stack** passes laravel-deployment's "Verify": `/kanban` answers 200 from the URL the browser uses. With sync
on, and an ssh `origin` with the deploy key registered (step 2, or the key `doctor --fix` printed) or an https
`origin` with `KANBAN_GIT_TOKEN` set, run
`docker compose -f docker-compose.local.yml exec app vendor/bin/kanban sync` as the compose user, never `-u root`. It
prints `sync: up to date`, `pulled` or `pushed`. Anything else: laravel-deployment, Trap "The board in `/kanban` shows
*Not synced* or *Not pushed*, or never shows others' changes". The page is no substitute: a hidden tab never syncs.

## 7. Commit main

Ask first: one commit or a few by concern. Board writes are already commits on `kanban`; main's `git status` ends clean.

## 8. First card through the loop

Path (b) takes the first real feature; path (a) a real bug or feature, planned as the `kanban` skill's "Planning
cards" says. Drive it with the `kanban` skill: `promote` → `start` → worker → `refresh` → evaluator → `finish`.
- With sync on, `start` is won by the push that lands, so `origin` must be reachable and writable from the host.
- Exit 9: stop and report. Never loop; never add `KANBAN_SYNC=off` yourself. The `kanban` skill owns the exit table.

## 9. Publish and report

`vendor/bin/kanban publish` syncs the board, then pushes `main`, merging `origin/main` when it moved.
- It pushes the board even under `KANBAN_SYNC=off`, so a local board pushes `main` with `git push origin main` instead.
- A shared board shows a `finish` to everyone at once, but its code only after `publish`. The owner decides when.

Report to the owner:
- the archive and the rules it added, the cards with open questions, the doctor output, `vendor/bin/kanban status`
  (sync state) and the first card's merge sha;
- the owner steps still open: address pools, old volumes, the deploy key to register;
- on every other clone, before its first Claude Code session: `composer install && vendor/bin/kanban attach`. Read
  what it prints. Each clone has its own key. If a session ran first, `cat .git/laravel-house/deploy_key.pub`.

## Upgrading a board

Both cases first:

1. **Decide sync.** With an `origin` and no `KANBAN_SYNC` anywhere, the first `status` or session start after the
   upgrade pushes the board. Only `sync auto (on)` in the status line shows it. To keep the board local, set
   `KANBAN_SYNC=off` in `.env` first. A `config/kanban.php` published earlier pins
   `'sync' => env('KANBAN_SYNC', 'off')`; sync then stays off until that line is deleted or set to `'auto'`. The
   `sync off but this board is published` warning appears only once the board is on `origin`.
2. **The board page** has no login. Ask whether the stack is visible beyond this machine (step 1, "The board page").

**(c) On an older house version:** update the house as the README's "Updating" says (a `^0.x` caret never crosses a
minor), then "Consolidating" when `status` says `board version 1`, step 6, the commit (step 7) and a restart (step 4).

**(d) On the separate package.** Every clone does this. Upgrade every project on a machine before starting new
worktree stacks there: the port registry is machine-wide.

1. **No live agents:** `vendor/bin/kanban status` shows none, or the migration refuses the rename partway.
2. Run it in one shell call. Between the remove and `doctor --fix`, the hooks name files that are gone. The house is
   required with no version, because a `^0.x` caret never crosses a minor:

   ```bash
   composer remove --dev petar-spasic/laravel-kanban && composer require --dev petar-spasic/laravel-house -W && vendor/bin/kanban doctor --fix && php artisan boost:update
   ```

   A clone that already pulled the commit from step 5 runs, in one call,
   `composer install && vendor/bin/kanban doctor --fix && php artisan boost:update`.
3. **Read every migration line.** `doctor --fix` renames `.git/laravel-kanban` to `.git/laravel-house`, moves the
   port registry, rewrites the old markers (`CLAUDE.md`, the agents, worktree `.env` files), switches
   `core.hooksPath`, drops the old package from `boost.json` and points the local compose file at `.git/laravel-house`.
   It refuses the rename while agents are live, and then also skips `attach`, so no second deploy key is made: stop
   them and run `doctor --fix` again. A `deploy_key` it keeps in `.git/laravel-kanban` differs from the one in
   `.git/laravel-house`: keep the one the repository knows, delete the other, and run it again. A
   `stacks.json … still used by <repo>` warning stays until those projects are upgraded; the last one's `doctor --fix`
   moves the registry.
4. **The container's half:** openssh-client in `Dockerfile.local`, `GIT_SSH_COMMAND` (both paths under
   `.git/laravel-house`, as `${KANBAN_GIT_SSH_COMMAND-…}`) and the `user:` line in the local compose, merged per
   laravel-deployment's Procedure, step 2 (`doctor --fix` adds the card-stack lines).
   Then rebuild and recreate with `docker compose -f docker-compose.local.yml up -d --build --wait`. Without
   `--build` the container has no ssh.
5. **Consolidate:** "Consolidating" below (the board is version 1 until then, so `doctor` fails).
6. **Finish:** step 6's container check, the commit (step 7), restart (step 4).

**(e) The separate package, no board.** Swap the packages, with no `doctor`, then adopt the board by path (a) or (b):

```bash
composer remove --dev petar-spasic/laravel-kanban && composer require --dev petar-spasic/laravel-house -W && php artisan boost:update
```

## Consolidating

A board from before version 2 (`board version 1: the owner runs /implement-kanban`) has several boards and a decisions
board. Every command but `sync`, `doctor`, `attach` and `kanban:install` refuses until it is consolidated.

1. **Drain:** no card in doing or review (`finish` or `stop` them with the owner).
2. **Every clone runs the new house** before anyone writes: the merge driver must write the same bytes everywhere.
3. `vendor/bin/kanban fold-boards --dry-run`, and show the owner every line: the moves, the archived decisions, each
   open question and the cards it lands on, the cards with no area, and the startable areas against `max_parallel`.
4. `vendor/bin/kanban fold-boards`. It runs the stored-name migration first, then makes one commit and pushes it. With
   sync off, `vendor/bin/kanban sync` once it is on.
5. Cards that got the same question are usually one piece of work: `kanban fold A B --into=C`.
6. Give every open card an `area:*` label (`set ID labels=+area:…`), with areas sized as the `kanban` skill's "Planning
   cards" says; then re-plan with the owner: fold enabler cards, group small ones.
7. "Judging decisions", then `vendor/bin/kanban validate` and `doctor`.

## Judging decisions

`docs/kanban/decisions.md` holds every decision as it was decided; nothing reads it unless asked. Go through it once,
entry by entry, and only then:

- **Skip** an entry that is already enforced (a template, a gate, the code itself), already a rule in a `CLAUDE.md`,
  superseded, or a one-off choice that shaped one piece of work.
- **Otherwise** write one imperative line in the `CLAUDE.md` of the directory it governs, without the history.
- Aim for at most one line per three entries; every line is read by every agent on every card.
- Show the owner the diff and the skipped entries with a reason each; commit on main with the owner's OK.
