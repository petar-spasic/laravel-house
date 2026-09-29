# Gotchas

Surprises from real runs, one block each: **symptom** → cause → fix. Add a block in the session the surprise happens
in, stating what is, not how it was found; remove it once the code fixes it.

**"Agent type not found", hooks don't run, or `status` shows the lease held by another session after
`kanban:install`, `doctor --fix` or an upgrade.** → Claude Code loads `.claude/agents` and settings hooks at session
start, and the old session holds the lease until 15 min idle. → Restart Claude Code, then
`vendor/bin/kanban lease --takeover`.

**`migrate:fresh`, `db:wipe` or odd tinker probes are denied, even on a worktree's own database.** → Permission rules
or the auto-mode classifier may soft-deny destructive database commands; the guard's allow does not override them.
→ `php artisan migrate --force`, then `php artisan db:seed --force` when the seeders are idempotent; prove behaviour
with tests and curl.

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
stack URL over CDP from Node ≥ 22 (global `WebSocket`); record the result with `set <ID> note="…"`.
