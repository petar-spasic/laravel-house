---
name: implement-kanban
description: >-
  Adoption checklist that brings a Laravel project onto petar-spasic/laravel-kanban:
  prerequisites, the package install, decisions recorded as board cards, a
  Claude Code restart with the lease takeover, the worktree-ready stack checked
  by `kanban doctor`, a first real card through the agent loop and the first
  publish. Three cases: an existing project whose decisions live in
  docs/decisions.md, a new project fresh from laravel-project-setup, and an
  installed project on an older package. Use when the owner types
  /implement-kanban, to adopt the package or to upgrade it. Triggers — implement
  kanban, adopt laravel-kanban, upgrade laravel-kanban, kanban:install,
  import-house-docs, board migration.
disable-model-invocation: true
---

# Implement laravel-kanban

This checklist brings a project onto petar-spasic/laravel-kanban, or upgrades it. The package owns the rest: its README
(requirements, what `kanban:install` changes, worktree stacks, Docker address pools) and the `kanban` skill (run loop,
exit codes, decisions, recovery). A surprise met on the way goes into its `references/gotchas.md` in the same session.

## Before you start

- Run it from the main session in the main checkout, never in a worktree.
- Ask the owner before anything that touches main's data, needs sudo, pushes, or commits. Commits carry no trailers.
- The install and an upgrade can push the board with no push command. Steps 1 and 2 and "Upgrading" say when.

## Choose the path

| Case | You recognise it by | Steps |
|---|---|---|
| (a) An existing project | `docs/decisions.md` (and `docs/ideas.md`) hold its decisions | 1–9 |
| (b) A new project | laravel-project-setup just ran; its choices live only in this chat and the files it wrote | 1–9 |
| (c) An installed project | the package is installed at an older version | "Upgrading an installed project", then step 6 |

## 1. Prerequisites: stop and report any that fail

- **Tools:** Laravel 12 or 13, PHP ≥ 8.3, git ≥ 2.42, Docker Compose v2, Laravel Boost (`boost.json`). `ssh-keygen` on
  the host too, when `origin` is ssh and the compose file below exists.
- **main:** `main` is clean. The require and the install change files on main. Step 7 commits them.
- **origin and sync:** `origin` decides sync (package README, "Team sync"). Without one, the board stays on this
  machine. With one, the install publishes the board branch (`kanban`, never `main`) with the host's git credentials and
  pushes every later write. The owner confirms: the repository is private; the user running the install can write to it;
  others share the board, or it stays on this machine (then `KANBAN_SYNC=off` in `.env` before step 2).
  - An empty `origin` (`git ls-remote --heads origin` prints nothing): push `main` first (owner's OK), then install.
    Otherwise the orphan board is pushed first, becomes the forge's default branch, and a clone checks out no code.
  - The URL scheme: with ssh, the container syncs the page with a deploy key. With https, it needs its own credential
    helper. The house image has none, so the page shows Not synced or Not pushed, while host syncs still push. The URL
    in `.git/config` names a host the container resolves: no `~/.ssh/config` alias, no `insteadOf`.
- **The board page:** `/kanban` has no login, and the local stack is LAN-visible by default. The owner chooses
  `KANBAN_UI_TOKEN` or `WEB_BIND=127.0.0.1` in `.env` (laravel-deployment, Trap "Anyone who can reach the web port reads
  and edits the board at `/kanban`").
- **The stack:** a `docker-compose.local.yml`. Without one, cards get a worktree only. Ask whether that is intended.

## 2. Install

```bash
composer require --dev petar-spasic/laravel-kanban
php artisan kanban:install --key=XYZ --dry-run    # read every line
php artisan kanban:install --key=XYZ
```

- `--key` is the card prefix: 2–10 uppercase letters or digits, starting with a letter. The owner picks it.
- `--dry-run` does not show the publish to `origin` (step 1 says when `KANBAN_SYNC=off` must come first).
- Read the output to the end. The `sync:` lines come just before the last line, `next: restart Claude Code`.
- A failed publish prints `sync: … (the board stays on this machine until …)` there and still exits 0. Report it.
- Every clone of a shared board runs the same package version: commit `composer.lock`.
- **Deploy key** (ssh `origin` and the local compose file): the install prints it once, when it makes it. It lives in
  `.git/laravel-kanban/deploy_key`, never committed; `cat .git/laravel-kanban/deploy_key.pub` shows it again. Show the
  owner the public half and the settings link (github.com origins only). A repository admin adds it with write access;
  run `gh repo deploy-key add …` only when the owner says so. It is a repository-wide write key the app container can
  read. With no compose file at install, no key is made: step 6 makes it once the file exists.

## 3. Record the decisions, before the restart

**Path (a):**
1. Run `vendor/bin/kanban import-house-docs --dry-run` and read every warning. Then run it without `--dry-run`.
2. Link the supersessions it reports: `vendor/bin/kanban set NEW supersedes=+OLD`. Decide duplicates with the owner.
3. Count check: `vendor/bin/kanban list --board=project/decisions --all` has one card per entry of the two files.
4. Delete both files and fix every reference:
   `grep -rn 'decisions\.md\|ideas\.md' --exclude-dir={vendor,node_modules,.claude,kanban} .`

**Path (b):** every setup and deployment choice (modules, PHP minor, ports, hosting tiers, …) becomes a decided card.
Every open item becomes a proposed card. Write them as the `kanban` skill's "Decisions" section says.

**Both:** in the root `CLAUDE.md`, the decided/open prose becomes a pointer: decisions are cards on `project/decisions`,
open questions are the proposed ones (`vendor/bin/kanban list --board=project/decisions --stage=proposed`). "Where the
docs live" gets the `docs/kanban/` row. Bugs the owner names become cards on `project/work`.

## 4. Restart Claude Code

Run `/exit`, then `claude --continue`. Agents and hooks load only at session start. Restart again after any
`doctor --fix`.
If `vendor/bin/kanban status` shows the pre-restart session holding the lease, run `vendor/bin/kanban lease --takeover`.

## 5. The stack

- **Path (b):** laravel-deployment built it worktree-ready. Step 6 only checks it.
- **Path (a):** bring compose, entrypoints, Caddy, Vite and phpunit to laravel-deployment's shapes ("Many stacks from
  one local compose"), and the seeders to laravel-project-setup's seeding standard. Dropping `container_name` and
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
- `no deploy key …`: `vendor/bin/kanban doctor --fix` makes and prints it; strip the `fix: ` prefix before pasting.
  The owner registers it (step 2), then restart (step 4). `ok deploy key` only means the file exists, not that the
  repository knows it.
- `sync off but this board is published`: the owner turns sync on (delete `KANBAN_SYNC=off` from `.env`, or the
  pinned `sync` line in `config/kanban.php`), or keeps sync off and accepts that the board lives on this machine only.
- `last sync failed`: stop and report.
- A Docker address-pool `warn` is the owner's sudo step.

**The main stack** passes laravel-deployment's "Verify": `/kanban` answers 200 from the URL the browser uses. With sync
on, an ssh `origin` and the deploy key registered (step 2, or the key `doctor --fix` printed), run
`docker compose -f docker-compose.local.yml exec app vendor/bin/kanban sync` as the compose user, never `-u root`. It
prints `sync: up to date`, `pulled` or `pushed`. Anything else: laravel-deployment, Trap "The board in `/kanban` shows
*Not synced* or *Not pushed*, or never shows others' changes". The page is no substitute: a hidden tab never syncs.

## 7. Commit main

Ask first: one commit or a few by concern. Board writes are already commits on `kanban`; main's `git status` ends clean.

## 8. First card through the loop

Path (b) takes the first real, small feature; path (a) a small real bug or feature. It has 1–3 acceptance criteria an
E2E test can show. Drive it with the `kanban` skill: `promote` → `start` → worker → `refresh` → evaluator → `finish`.
- With sync on, `start` is won by the push that lands, so `origin` must be reachable and writable from the host.
- Exit 9: stop and report. Never loop; never add `KANBAN_SYNC=off` yourself. The `kanban` skill owns the exit table.

## 9. Publish and report

`vendor/bin/kanban publish` syncs the board, then pushes `main`, merging `origin/main` when it moved.
- It pushes the board even under `KANBAN_SYNC=off`, so a local board pushes `main` with `git push origin main` instead.
- A shared board shows a `finish` to everyone at once, but its code only after `publish`. The owner decides when.

Report to the owner:
- the decision cards per stage, the doctor output, `vendor/bin/kanban status` (sync state) and the first card's merge
  sha;
- the owner steps still open: address pools, old volumes, the deploy key to register;
- on every other clone, before its first Claude Code session: `composer install && vendor/bin/kanban attach`. Read
  what it prints. Each clone has its own key. If a session ran first, `cat .git/laravel-kanban/deploy_key.pub`.

## Upgrading an installed project

The recipe is the package README, Install (the "Upgrade:" paragraph). It leaves these steps to the house, in order:

1. **Decide sync.** With an `origin` and no `KANBAN_SYNC` anywhere, the first `status` or session start after the
   upgrade pushes the board. Only `sync auto (on)` in the status line shows it. To keep the board local, set
   `KANBAN_SYNC=off` in `.env` first. A `config/kanban.php` published earlier pins
   `'sync' => env('KANBAN_SYNC', 'off')`; sync then stays off until that line is deleted or set to `'auto'`. The
   `sync off but this board is published` warning appears only once the board is on `origin`.
2. **Require with no constraint:** `composer require --dev petar-spasic/laravel-kanban`. A `^0.1` pin stays on 0.1.x,
   and `composer update` says nothing. All clones of a shared board upgrade to one version (commit `composer.lock`).
3. **The board page** has no login. Ask whether the stack is visible beyond this machine (step 1, "The board page").
4. **The container's half:** openssh-client in `Dockerfile.local`, `GIT_SSH_COMMAND` and the `user:` line in the local
   compose, merged per laravel-deployment's Procedure, step 1. Then rebuild and recreate with
   `docker compose -f docker-compose.local.yml up -d --build --wait`. Without `--build` the container has no ssh.
5. **Finish:** `vendor/bin/kanban doctor --fix` (prints a deploy key once), step 6's container check, restart (step 4).
