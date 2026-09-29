---
name: implement-kanban
description: >-
  Adoption checklist that brings a Laravel project onto petar-spasic/laravel-kanban:
  prerequisites, the package install, decisions recorded as board cards, a
  Claude Code restart with the lease takeover, the worktree-ready stack checked
  by `kanban doctor`, a first real card through the agent loop and the first
  publish. Two paths: an existing project whose decisions live in
  docs/decisions.md, and a new project fresh from laravel-project-setup. Use
  when the owner types /implement-kanban. Triggers — implement kanban, adopt
  laravel-kanban, kanban:install, import-house-docs, board migration.
disable-model-invocation: true
---

# Implement laravel-kanban

Run it from the main session in the main checkout, never in a worktree. The package owns everything this checklist
does not: its README (requirements, what `kanban:install` changes, worktree stacks, Docker address pools) and the
`kanban` skill (run loop, exit codes, decisions, recovery, `references/gotchas.md`). A surprise met on the way goes
into that gotchas file in the same session.

Ask the owner before anything that touches main's data, needs sudo, or commits; commits carry no trailers.

**Two paths.** (a) An existing project: `docs/decisions.md` (and `docs/ideas.md`) hold its decisions. (b) A new
project: laravel-project-setup just ran, and its choices live only in this conversation and the files it wrote.

## 1. Prerequisites — stop and report any that fail

- Laravel 12 or 13, PHP ≥ 8.3, git ≥ 2.42, Docker Compose v2, Laravel Boost (`boost.json`).
- `main` is clean and has an `origin` remote: `publish` pushes there.
- A `docker-compose.local.yml`; without one cards get a worktree only — ask whether that is intended.

## 2. Install

```bash
composer require --dev petar-spasic/laravel-kanban:^0.1
php artisan kanban:install --key=XYZ --dry-run    # read every line
php artisan kanban:install --key=XYZ
```

`--key` is the 2–5 letter card prefix; the owner picks it.

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

`vendor/bin/kanban doctor` (every line ok, exit 0) and `vendor/bin/kanban validate`. Fix every `fail`; a Docker
address-pool `warn` is the owner's sudo step. The main stack passes laravel-deployment's "Verify".

## 7. Commit main

Ask first; one commit, or a few by concern. Board writes are already commits on `kanban`; `git status` on main is
clean afterwards.

## 8. First card through the loop

(b) The first real, small feature; (a) a small real bug or feature. It has 1–3 acceptance criteria an E2E test can
show. Drive it with the `kanban` skill: `promote` → `start` → worker → `refresh` → evaluator → `finish`.

## 9. Publish and report

`vendor/bin/kanban publish` pushes `kanban` and `main` once. Report to the owner: decision cards per stage, the doctor
output, the first card's merge sha, and the owner steps still open (address pools, old volumes).
