# Gotchas

Surprises from real runs, one block each: **symptom** → cause → fix. Add a block in the session the surprise happens
in, stating what is, not how it was found; remove it once the code fixes it.

**"Agent type not found", hooks don't run, or `status` shows the lease held by another session after
`kanban:install`, `doctor --fix` or an upgrade.** → Claude Code loads `.claude/agents` and settings hooks at session
start, and the old session holds the lease until 15 min idle. → Restart Claude Code, then
`vendor/bin/kanban lease --takeover`.

**`migrate:fresh`, `db:wipe` or odd tinker probes are denied, even on a worktree's own database.** → Permission rules
or the auto-mode classifier may soft-deny destructive database commands; an allow rule does not override them.
→ `php artisan migrate --force`, then `php artisan db:seed --force` when the seeders are idempotent; prove behaviour
with tests and curl.
Nothing stops an agent whose cwd is the main checkout from running them against main's database: agents must reset a
database only when `pwd` is their worktree and its `.env` `DB_PORT` differs from main's.

**Copied `node_modules` get wiped on a worktree stack's first start.** → `git worktree add` stamps the lockfiles with
the current time, so mtime (`-nt`) sentinels in the entrypoint reinstall. → sha256 sentinels.

**About 6 stacks machine-wide, then compose fails on networks.** → The host's LAN overlaps Docker's default address
pools. → Widen them (README, "Docker address pools"); `doctor` shows the headroom.

**Tests in a worktree hit main's database.** → `phpunit.xml` sets `DB_HOST`/`DB_PORT`, which win over the worktree
`.env`. → Remove them; `doctor` warns.

**Boost's database and log tools show main's data inside a worktree.** → Boost's MCP server runs from the main
checkout. → `php artisan db:table` / `db:show` from the worktree.

**A compose overlay (`-f docker-compose.local.yml -f <overlay>`) fails with `external volume "…" not found`, or runs on
stale data.** → It names a local volume with a fixed `external` name, while compose names the local stack's volumes
`<project>_<volume>`. → Reference `${COMPOSE_PROJECT_NAME}_<volume>`, or take the name from a variable; `doctor`
warns.

**A tool finds no files inside a worktree.** → Symfony Finder's `ignoreVCSIgnored` takes the first parent with a
`.git` directory as the repository root; a worktree's `.git` is a file, so it reaches main, whose `.gitignore` ignores
`/.claude/worktrees`. → Pass explicit paths, or turn the VCS filter off.

**A criterion needs a real browser, and `chrome-headless-shell` on the host fails with `libatk-1.0.so.0: cannot open
shared object file`.** → The host lacks the browser's system libraries. → Run `chromium --headless=new --no-sandbox
--remote-debugging-port=9333` in a throwaway `debian:trixie-slim` container on the host network and drive the card's
stack URL over CDP from Node ≥ 22 (global `WebSocket`); record the result with `set <ID> note="…"`. While it runs,
workers and evaluators can drive it too (`http://127.0.0.1:9333`, no `docker` needed): say so when you SendMessage them.

**A worker or evaluator runs `move`, `finish` or another main-only command.** → `KANBAN_SESSION` is exported for the whole
session (CLAUDE_ENV_FILE), so a subagent's Bash counts as `main`. Main-only commands (`start`, `move`, `set`, `stop`,
`finish`, `lease --takeover`, …) refuse to run with a card worktree as the working directory (`… runs from the main
checkout`), so a subagent in its worktree cannot; one that `cd`s out still can. → Tighten the agent's instructions or
send it back with a note; the board is a git branch, so `git -C docs/kanban log` shows what it changed.

**`stop` refuses: "is being worked on at <host>".** → The card was started on another machine (`work.host`, or the
claim's `user@host`); stopping it here would revert that machine's live card. The brief shows such cards as `on <host>`
instead of `no agent`. → Stop it on that machine, or `stop --force` to revert it here anyway.

**A rebase is in progress in `docs/kanban` and every write exits 5 ("a git rebase is in progress").** → Someone (or a
killed `git pull --rebase` in the board) left git mid-rebase; kanban only recovers rebases it started itself. → Finish
or abort it: `git -C docs/kanban rebase --continue` or `--abort`. A rebase kanban's own killed `sync` left is reattached
on the next command.

**`sync` moved a card back for a moment, or `sync` reports a board that changed.** → A card moved with `move --board` and
edited on another machine is merged by id, not by git's rename detection: the move is undone before the rebase and
re-applied after; when origin moved it too, origin's board wins and both sets of edits are kept.

**Session start removed an `agent-a…` worktree.** → Claude Code never calls WorktreeRemove for isolated agents, so
SessionStart removes clean ones (up to ten an hour) once they have had no git activity and no running stack for a day.
Uncommitted work is never touched; commit or keep the worktree busy to keep it.

**`board cards/…` or `board assets/…` is refused.** → The local UI serves `/kanban/cards/…` and `/kanban/assets/…`
itself; pick another epic name.
