# Gotchas

Surprises from real runs, one block each: **symptom** → cause → fix. This file ships with the package and is
overwritten on update: a surprise specific to a project goes into that project's `CLAUDE.md`, and a defect in the
package goes upstream (`--upstream`, `kanban upstream`).

**`kanban set` on a card in doing, review or done exits 3 with "a locked stage".** → Those stages are locked by
default: the owner and the main session may add a note, block or unblock, tick or untick, and reword a criterion with
`accept[N]="…" --reason="…"`. → `kanban stop ID --to=ready` for anything else on a card in doing or review,
`kanban set … --force` from the main session when the owner asks (the only way for a done card), or list other stages
(or `[]`) as `locked` in `docs/kanban/kanban.json`.

**`next` starts one card while Ready holds several.** → Cards that share an `area:*` label never run at once, and a
card in review holds its area until `finish`. → `next -v` names the wait; finish approved cards first, and plan one card
per area (the kanban skill, "Planning cards").

**A card's commits are not on main's `card/…` branch while it is in progress.** → A card's directory is a clone of
main; its branch reaches main when a kanban command needs it there (`report`, `context`, `refresh`, `finish`, `stop`).
→ Read the work in the clone, or run `kanban context ID`.

**"Agent type not found", hooks don't run, or `status` shows the lease held by another session after
`kanban:install`, `doctor --fix` or an upgrade.** → Claude Code loads `.claude/agents` and settings hooks at session
start. → Restart Claude Code: the old session frees the lease as it ends, and the new one takes it. `vendor/bin/kanban
lease --takeover` covers an old session that crashed.

**A worker's stop is refused: "A merge of main carries changes neither side had".** → Work that was not committed
went into a merge commit (a conflict resolved with `git add -A`), where no review sees it as the card's change. →
`vendor/bin/kanban rebuild-branch ID` from the card's clone makes the branch one commit with the same files; then
report again. `refresh` never merges main into uncommitted changes.

**Copied `node_modules` get wiped on a card stack's first start.** → A card's checkout stamps the lockfiles with the
current time, so mtime (`-nt`) sentinels in the entrypoint reinstall. → sha256 sentinels.

**`start` is refused: "… runs the app as uid …, but the checkout's user is …".** → The card's container would run as
another user than the one who owns the clone, so it could not write it (a root host, or a user that is not 1000). →
`vendor/bin/kanban doctor --fix` sets `HOST_UID`/`HOST_GID` in `.env`; rebuild main's stack, then start again.

**About 6 stacks machine-wide, then compose fails on networks.** → The host's LAN overlaps Docker's default address
pools. → Widen them (house README, "Worktree Stacks", "Docker Address Pools"); `doctor` shows the headroom.

**Tests in a worktree hit main's database.** → `phpunit.xml` sets `DB_HOST`/`DB_PORT`, which win over the worktree
`.env`. → Remove them; `doctor` warns.

**Boost's database and log tools show main's data inside a worktree.** → Boost's MCP server runs from the main
checkout. → `php artisan db:table` / `db:show` from the worktree.

**A compose overlay (`-f docker-compose.local.yml -f <overlay>`) fails with `external volume "…" not found`, or runs on
stale data.** → It names a local volume with a fixed `external` name, while compose names the local stack's volumes
`<project>_<volume>`. → Reference `${COMPOSE_PROJECT_NAME}_<volume>`, or take the name from a variable; `doctor`
warns.

**`stop` refuses: "is being worked on at <host>".** → The card was started on another machine (`work.host`, or the
claim's `user@host`); stopping it here would revert that machine's live card. The brief shows such cards as `on <host>`
instead of `no agent`. → Stop it on that machine, or `stop --force` to revert it here anyway.

**A rebase is in progress in `docs/kanban` and every write exits 5 ("a git rebase is in progress").** → Someone (or a
killed `git pull --rebase` in the board) left git mid-rebase; kanban only recovers rebases it started itself. → Finish
or abort it: `git -C docs/kanban rebase --continue` or `--abort`. A rebase kanban's own killed `sync` left is reattached
on the next command.

**`sync` moved a card back for a moment, or `sync` reports a board that changed.** → A card moved with `move --board`
and edited on another machine is merged by id, not by git's rename detection: the move is undone before the rebase and
re-applied after; when origin moved it too, origin's board wins and both sets of edits are kept.

**Session start removed an `agent-a…` worktree.** → Claude Code never calls WorktreeRemove for isolated agents, so
SessionStart removes clean ones (up to ten an hour) once they have had no git activity and no running stack for a day.
Uncommitted work is never touched; commit or keep the worktree busy to keep it.

**`board cards`, `board boards` or `board assets` is refused.** → The local UI serves those paths under `/kanban`
itself; pick another board name.

**The UI or a script gets 403 ("Kanban writes need the X-Kanban header" / "Cross-site requests … are refused").** → The
UI has no session to hold a CSRF token, so its API refuses requests other sites cause and writes without the custom
header a browser cannot send cross-site. Use the page itself; a script sends `X-Kanban: 1` with every write.

**The UI answers 403 "Kanban answers only to this machine's own names".** → The UI refuses a Host header that is not an
IP, `localhost`, `*.localhost`, `*.test`, the host of `APP_URL` or of `LOCAL_APP_URL`: a page that points its own DNS
name at this machine would otherwise be same-origin with the board. Add the name you use (a `.lan` or Bonjour name, a
proxy's name) to `kanban.ui.hosts` in the published config.

**Middleware, sessions or `APP_KEY` have no effect on the UI.** → It runs outside the `web` group and needs no session,
cookie or CSRF token, so `ui.middleware` is empty by default and it works without `APP_KEY` or a session driver. A
`'web'` entry in a published `ui.middleware` is ignored, and so is anything that needs a session: an `auth` entry makes
the page redirect to your login route (or fail without one) and the API answer 401. Gate the UI with `KANBAN_UI_TOKEN`;
other stateless middleware you list there still applies.

**The UI shows unstyled black on white, or without colours.** → The stylesheet uses `light-dark()`, which browsers have
had since 2024 (Chrome 123, Firefox 120, Safari 17.5). Update the browser.

**Sync is on, but the board in the container never picks up what others pushed, and there is no notice.** → The pull is
a detached `php bin/kanban sync --background` started from the web request. It needs `php` and `git` on the container's
PATH, `exec()` not listed in `disable_functions`, credentials for the remote, and a user that can write the mounted
`.git` and `.git/laravel-house`. Missing credentials show as a *Not synced* notice; a disabled `exec()` shows nothing. →
Run `vendor/bin/kanban sync` inside the container to see the error. `KANBAN_PULL_SECONDS=0` switches the timed pull off.

**In the container, `kanban sync` says `Permission denied (publickey)`.** → Over ssh the container uses the clone's
deploy key (`.git/laravel-house/deploy_key`, made by `install`, `attach` or `doctor --fix` when the project has a local
compose file), and the repository does not know its public half yet. → `cat .git/laravel-house/deploy_key.pub`, add it
as a deploy key with write access (an admin of the repository can), then sync again. `ssh` also refuses the key when the
container user cannot read it (`HOST_UID`/`HOST_GID` of the compose file must be yours), and an https `origin` syncs
with `KANBAN_GIT_TOKEN` in `.env` instead: a fine-grained token with read and write on this repository's contents,
served only for origin's host (laravel-deployment `references/board-page.md`).

**The UI says "Not pushed: N commits (…)", or `status` says "last sync failed".** → The background sync of this clone
(after each write when sync is on) could not fetch, rebase or push, and the same error came back. The text names it: an
unreachable remote, missing git credentials (in a container too), a push rejected three times because others kept
pushing, or a rebase that failed. → `vendor/bin/kanban sync` on the host prints the same error; fix it and run it again.
Other machines see nothing of an unpushed clone.

**"Sync stopped after a pull: … dependency cycle" (or a cap such as 10 labels).** → Two people's edits were each valid
and together are not (A made X depend on Y while B made Y depend on X). The clone is rebased but will not push an
invalid board. → `vendor/bin/kanban validate` names the card; change one side with `kanban set`, then `vendor/bin/kanban
sync`.

**My edit of a card vanished after a sync.** → Someone changed the same field (title, description, a blocked reason, or
the stage/claim) before your commit reached them; the merge keeps the newer `updated` and does not blend texts. → The
replaced text is in the card's log: `kanban show ID` (line *merge kept the other version of …*) or the *Activity* list
in the UI; copy it back with `kanban set`. Keep the house at one version on every clone, or an older clone merges
without writing the record.

**"report stays staged: … is now ready" (or "held on <host>") when a worker stops.** → The card moved on after the
worker started: another person re-claimed it, sent it back, or stopped it, and that change reached this machine by sync.
The report belongs to work that is no longer this machine's, so nothing is written and the card is not blocked. → Read
the card (`kanban show ID`). If the work is still wanted here, get the card back into this machine's doing and run
`vendor/bin/kanban apply ID`; otherwise ignore the report, it is cleaned up with the finished cards.

**"Changed elsewhere. The latest version:" under a text I was editing.** → Someone (another person, an agent, or you on
the command line) changed that same text after you opened the editor. Your text is untouched. → Read the latest version,
then *Keep mine* (writes yours over it) or *Use theirs* (drops yours). A closed editor keeps its draft for the card;
opening it again after the text changed shows the same choice.

**`status` says "sync off but this board is published: claims are not coordinated with other machines".** → This clone
has `origin/kanban`, yet sync is off here: `KANBAN_SYNC=off` in `.env` or the environment, or a published
`config/kanban.php` whose line reads `'sync' => env('KANBAN_SYNC', 'off')`. Two machines can then start the same card or
fill one area, and the merge keeps one claim while the other agent keeps working. → Delete that setting (the default is
`auto`), or set `KANBAN_SYNC=on`; `vendor/bin/kanban sync` once to catch up.

**A claim exits 9 with "while sync is on a claim needs the remote".** → The claim is won by the push that lands, so it
cannot be made while the remote is unreachable; no claim commit was left behind. → Retry later with a pause (a scheduler
must not loop on it), or, only when the owner agrees, run the same command once with `KANBAN_SYNC=off` in front (for
`start`, `KANBAN_SYNC=off vendor/bin/kanban start ID`); that card is then claimed on this machine alone and reconciles
at the next sync.

