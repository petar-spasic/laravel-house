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

Run it from the main session in the main checkout, never in a worktree. The package owns everything this checklist
does not: its README (requirements, what `kanban:install` changes, worktree stacks, Docker address pools) and the
`kanban` skill (run loop, exit codes, decisions, recovery, `references/gotchas.md`). A surprise met on the way goes
into that gotchas file in the same session.

Ask the owner before anything that touches main's data, needs sudo, pushes, or commits; commits carry no trailers.
The install and an upgrade can push the board without a push command: steps 1 and 2 and "Upgrading an installed
project" say when.

**Three paths.** (a) An existing project: `docs/decisions.md` (and `docs/ideas.md`) hold its decisions. (b) A new
project: laravel-project-setup just ran, and its choices live only in this conversation and the files it wrote.
(c) An installed project on an older package: go to "Upgrading an installed project", then step 6.

## 1. Prerequisites — stop and report any that fail

- Laravel 12 or 13, PHP ≥ 8.3, git ≥ 2.42, Docker Compose v2, Laravel Boost (`boost.json`); `ssh-keygen` on the host
  when `origin` is ssh and the compose file below exists.
- `main` is clean. `composer require` and the install leave the composer files and their own files for step 7.
- `origin` decides sync (package README, "Team sync"). With one, the install publishes the board branch (`kanban`,
  never `main`) with the host's git credentials and every later write is pushed: the owner confirms it is private,
  that the user running the install can write to it, and whether others will share the board or it stays on this machine
  (then `KANBAN_SYNC=off` in `.env` before step 2). Without an `origin` the board stays on this machine.
  - An empty `origin` (`git ls-remote --heads origin` prints nothing): push `main` first
    (owner's OK), then install. Otherwise the first branch pushed, the orphan board, becomes the default branch on the
    forge and a clone checks out no code.
  - Its URL scheme: ssh gives the page's container sync a deploy key; https syncs from the page only with a credential
    helper of the container's own (the house image has none: the page then shows Not synced / Not pushed; the host's
    syncs still push). The URL in `.git/config` names a host the container resolves: no `~/.ssh/config` alias, no
    `insteadOf` rewrite.
- `/kanban` has no login and the local stack is LAN-visible by default: ask the owner to choose `KANBAN_UI_TOKEN` or
  `WEB_BIND=127.0.0.1` in `.env` (laravel-deployment, Trap "Anyone who can reach the web port reads and edits the board
  at `/kanban`").
- A `docker-compose.local.yml`; without one cards get a worktree only — ask whether that is intended.

## 2. Install

```bash
composer require --dev petar-spasic/laravel-kanban
php artisan kanban:install --key=XYZ --dry-run    # read every line
php artisan kanban:install --key=XYZ
```

`--key` is the card prefix, 2–10 uppercase letters or digits starting with a letter; the owner picks it.
`--dry-run` does not show the publish to `origin`: a board to keep on this machine needs `KANBAN_SYNC=off` in `.env`
before the real install. Read the output to the end: the `sync:` lines sit between the three review/commit/other-machines
`next:` lines and the final `next: restart Claude Code`; a failed publish prints
`sync: … (the board stays on this machine until …)` there and still exits 0: report it. Every clone of a shared board
runs the same package version (commit `composer.lock`).

With an ssh `origin` and the local compose file the install prints a deploy key once, when it makes it
(`.git/laravel-kanban/deploy_key`, never committed; `cat .git/laravel-kanban/deploy_key.pub` shows it again). Show the
owner the public half (and the settings link, printed only for a github.com origin): a repository admin adds it with
write access (`gh repo deploy-key add …` only when the owner says so). It is a repository-wide write key the app
container can read. With no compose file at install no key is made: step 6 makes it once the file exists.

## 3. Record the decisions — before the restart

- (a) `vendor/bin/kanban import-house-docs --dry-run`, read every warning, then run it without `--dry-run`. Link the
  supersessions it reports (`vendor/bin/kanban set NEW supersedes=+OLD`); decide the duplicates it prints with the
  owner. Count check: `vendor/bin/kanban list --board=project/decisions --all` has one card per entry of the two
  files. Then delete both files and fix every reference:
  `grep -rn 'decisions\.md\|ideas\.md' --exclude-dir={vendor,node_modules,.claude,kanban} .`
- (b) Every setup and deployment choice (modules, PHP minor, ports, hosting tiers, …) becomes a decided card and every
  open item a proposed card, written as the `kanban` skill's "Decisions" section says.
- Both: in the root `CLAUDE.md`, the decided/open prose becomes a pointer (decisions are cards on `project/decisions`;
  open questions are the proposed ones: `vendor/bin/kanban list --board=project/decisions --stage=proposed`), and
  "Where the docs live" gets the `docs/kanban/` row. Bugs the owner names become cards on `project/work`.

## 4. Restart Claude Code

`/exit`, then `claude --continue`: agents and hooks load only at session start (the same holds after any
`doctor --fix`). When `vendor/bin/kanban status` shows the lease held by the pre-restart session, that session was
this one: `vendor/bin/kanban lease --takeover`.

## 5. The stack

- (b) laravel-deployment built it worktree-ready; step 6 only checks it.
- (a) Bring compose, entrypoints, Vite and phpunit to laravel-deployment's shapes ("Many stacks from one local
  compose"), and the seeders to laravel-project-setup's seeding standard. Dropping `container_name` and volume `name:`s
  renames main's volumes, so the data moves once (about 2 minutes of downtime):

  ```bash
  docker compose -f docker-compose.local.yml down          # the old file: containers go, volumes stay
  # land the new files; main .env += COMPOSE_PROJECT_NAME={{app}}-local
  docker compose -f docker-compose.local.yml up --no-start --build
  docker run --rm --entrypoint sh -v <old postgres volume>:/from:ro -v {{app}}-local_postgres_local:/to postgres:16-alpine -c 'cp -a /from/. /to/'
  docker compose -f docker-compose.local.yml up -d --wait
  ```

  Redis holds only cache, queue and sessions: it may start empty. The old volumes and image go a week later, with the
  owner.

## 6. Doctor

Run `vendor/bin/kanban doctor` on the host, never in the container (it sees host paths), and `vendor/bin/kanban
validate`. No `fail`; read every `warn`:

- `no deploy key …`: `vendor/bin/kanban doctor --fix` makes and prints it (lines prefixed `fix: `; strip that before
  pasting the key); the owner registers it (step 2), then restart Claude Code (step 4). `ok deploy key` only says the
  file exists, not that the repository knows it.
- `sync off but this board is published`: the owner chooses: sync on (delete `KANBAN_SYNC=off` from `.env`, or the
  pinned `sync` line in `config/kanban.php`) or an accepted solo board.
- `last sync failed`: stop and report.
- A Docker address-pool `warn` is the owner's sudo step.

The main stack passes laravel-deployment's "Verify" (`/kanban` answers 200 from the URL the browser uses). With sync on,
an ssh `origin` and the deploy key registered by the owner (step 2, or the key printed by `doctor --fix` in step 6),
`docker compose -f docker-compose.local.yml exec app vendor/bin/kanban sync`, as the compose user and never
`-u root`, prints `sync: up to date`, `pulled` or `pushed`. Anything else: laravel-deployment, Trap "The board
in `/kanban` shows *Not synced* or *Not pushed*, or never shows others' changes". The page is no substitute for the
command: a hidden tab never syncs.

## 7. Commit main

Ask first; one commit, or a few by concern. Board writes are already commits on `kanban`; `git status` on main is
clean afterwards.

## 8. First card through the loop

(b) The first real, small feature; (a) a small real bug or feature. It has 1–3 acceptance criteria an E2E test can
show. Drive it with the `kanban` skill: `promote` → `start` → worker → `refresh` → evaluator → `finish`. With sync on,
`start` is won by the push that lands, so `origin` must be reachable and writable from the host. Exit 9: stop and report
to the owner; never loop and never add `KANBAN_SYNC=off` yourself (the `kanban` skill owns the exit table).

## 9. Publish and report

`vendor/bin/kanban publish` syncs the board, then pushes `main` (merging `origin/main` when it moved). It pushes the
board even under `KANBAN_SYNC=off`: a board kept local pushes `main` with `git push origin main` (ask first). On a
shared board `finish` is visible to everyone at once and its code only after `publish`; the owner decides when (package
README "Team sync").

Report to the owner: decision cards per stage, the doctor output, `vendor/bin/kanban status` (sync state), the first
card's merge sha, and the owner steps still open: address pools, old volumes, the deploy key to register, and on every
other clone `composer install && vendor/bin/kanban attach` before its first Claude Code session (read what it prints;
`cat .git/laravel-kanban/deploy_key.pub` if a session ran first; each clone's key is its own).

## Upgrading an installed project

The recipe is the package README, Install (the "Upgrade:" paragraph). What it leaves to the house, in order:

1. Decide sync. With an `origin` and no `KANBAN_SYNC` anywhere, the first `status` or session start after the upgrade
   pushes the board (only `sync auto (on)` in the status line tells): a board kept local needs `KANBAN_SYNC=off` in
   `.env` first. A `config/kanban.php` published earlier pins `'sync' => env('KANBAN_SYNC', 'off')`: sync stays off (the
   `sync off but this board is published` warning appears only once the board is on `origin`) until that line is deleted
   or set to `'auto'`. Ask before any push.
2. `composer require --dev petar-spasic/laravel-kanban` with no constraint: a `^0.1` pin stays on 0.1.x and
   `composer update` says nothing. Every clone of a shared board upgrades to the same version (commit `composer.lock`).
3. The `/kanban` page has no login: ask whether the stack is visible beyond this machine (step 1, the `/kanban` bullet).
4. The container's half: openssh-client in `Dockerfile.local`, `GIT_SSH_COMMAND` and the `user:` line in the local
   compose, merged per laravel-deployment's Templates step 1, then rebuilt and recreated
   (`docker compose -f docker-compose.local.yml up -d --build --wait`; without `--build` the container has no ssh).
5. `vendor/bin/kanban doctor --fix` (prints a deploy key once), step 6's container check, a Claude Code restart.
