# Kanban protocol reference

Every command runs as `vendor/bin/kanban <command>` (no app boot) or `php artisan kanban:<command>`. Output is plain
text, one fact per line; errors go to stderr. Card ids accept a unique prefix of ≥ 3 characters, any case,
`I`/`L` → `1`, `O` → `0`.

## Exit codes

| Code | Meaning | React |
|---|---|---|
| 0 | ok | — |
| 1 | unexpected | read stderr; `doctor`; retry once. `finish`: the lease is given back (kept mid-push), and the third failure in one phase blocks the card |
| 2 | invalid input | fix the flags or values |
| 3 | refused (policy or precondition) | the message names the rule; fix it or pick another card |
| 4 | not found or ambiguous | longer id prefix; `attach` when the board is missing |
| 5 | git conflict | `refresh` of a doing card left a merge of main in progress (its worker concludes it; `stack up\|wait\|reload` exit 5 meanwhile); a git rebase is in progress in `docs/kanban` (finish or abort it); or a rebase of the board onto origin failed and was aborted (the message names why; every clone of a board must run the same package version) |
| 6 | lock or lease | another writer or orchestrator; wait, or `lease --takeover` (your own previous session, or with the owner's OK) |
| 7 | stack | `stack <ID> logs`, `doctor`; fix, then `stack <ID> up`. `finish`: the merge stack (`stack _merge logs`), a foreign `.claude/worktrees/_merge`, or a `finish.check` command not found where it runs (exit 126/127: write it as the app container runs it, or this machine with `KANBAN_AGENT_SHELL=host`; missing on main too, the merge is let go uncounted); the lease is given back, unless the merge holds its merger's work or waits on its merger (kept for the next `finish`; the third failure in a phase blocks the card); a merge stack that fails only on the merged tree's docker files blocks the card the third time |
| 8 | claim lost | another machine took the card; `next`. A card that left `ready` is exit 3. A scheduler skips that card for the rest of its run. `finish`: another machine took the merge lease over; nothing was pushed, the card stays queued |
| 9 | remote | network down, or a push rejected 3 times in a row: transient, retry later with backoff; never force-push. A claim that exits 9 left no claim commit behind here. A `start` whose push reached origin all the same (the remote failed before it could say so) is finished by running `start` again, or by `kanban run`. `finish`: the merge was made again 3 rounds running (main moved, on origin or locally, or the merge clone held commits no check ran on), or the remote failed; the card stays queued, and a merge that holds its merger's work or waits on its merger keeps it and the lease, uncounted. A push of main that failed with an error: when origin answers that it did not land, the lease is given back (the third blocks the card); when origin does not answer, the lease is kept and the next `finish` asks origin first |
| 10 | merged, then a step failed | `finish` only: the card is done; following main on this machine failed, before the merge or after its push (the fast-forward of the main checkout, an install, rebuilding main's stack, `migrate`, `finish.after`), or the teardown did (stderr names each; the steps after it still run, but a failed install skips `migrate` and `finish.after`). Fix it on main; never block the card. `finish --follow`: following main failed; nothing merged |
| 11 | waits | `finish` only (and `stop` of a card being pushed, or being merged by a `finish` that `kanban run` did not start): the merge lease is held elsewhere, another card goes first, an agent of the card is live, main is red (the queue holds until origin's main moves), a check timed out (the queue holds until origin's main moves or that card is queued no more at the head it timed out on), a merge already runs here, a push is in flight, or a local main checkout that is off main, dirty or holds an untracked file where the card adds one. Nothing changed; retry later (`kanban run` does), but fix the main checkout: retrying does not. Without a remote, a fast-forward of the main checkout that fails after the checks counts a strike, and the third blocks the card |
| 12 | the merger's turn | `finish` only: the merge conflicts, or a gate, install or `finish.check` command failed on the merged tree (one that timed out: exit 13). The lease stays held; it prints the merger's spawn line, `merger: Agent(…)` (`kanban run` launches it). After its result is applied, `finish <ID>` goes on |
| 13 | left the merge | `finish` only: the card went back to doing (the merger's `back`, still red after 3 merger rounds, leftover conflict markers), its branch moved past its approval (approval cleared: an evaluator judges it again), main is red or a check timed out (the queue holds; the card keeps its approval and waits), or it left review during the merge. The lease is given back |
| 75 | stack still starting | run `stack wait` again |

## Actors

- `main`: `KANBAN_SESSION` set (SessionStart exports it). `owner`: a terminal without it, or the UI.
- `planner` / `worker` / `evaluator`: bound to their card when they start (SubagentStart claims the main session's spawn
  record); `plan` / `report` / `verdict` also require the cwd to be the card's clone, where an agent's shell starts.
  `merger`: bound to the card `merge.json` names, in the merge clone `.claude/worktrees/_merge`, where `merged` must
  run. An agent runs
  `vendor/bin/kanban` as a command of its own (on this machine; a leading `cd <dir> &&` into its card's directory is
  dropped); its other commands run in the card's container.
- `--force` is main-only and logged.
- A log entry may carry `who`: the person at that keyboard (`KANBAN_USER`, else git `user.name`, else the name in an explicit `KANBAN_GIT_AUTHOR`; none known: no key). `by` stays the role, `who` is shown beside it (`owner (Ana)`, `worker (Ana)`) and is never used for claims, notes, locks or merges. An entry written by an agent carries the person whose machine ran it.

## Read (anyone)

| Command | Does |
|---|---|
| `status [--json]` | The brief: branch, unpushed, WIP, doing/review with agents and URLs (review cards in merge-queue order), the merge lease, blocked, questions, why ready cards wait, hubs, rule files over 40 KB, pending upstream findings, checks |
| `list [--board= --epic= --stage= --type= --label= --all --json]` | Default: planning, ready, doing, review, plus blocked anywhere |
| `show ID [--json --log=10 --plan]` | Header, body, criteria, the plan's size and commit, deps with stages, claim, work, agent state, log; at work the agent's spawn line (an approved card's is none, or its merger's while its merge here waits on one: a conflict or a red check), after a reject the message for the worker. `--plan` prints only the plan (exit 4 without one) |
| `context [ID] [--evaluate]` | For agents; card from the cwd: notes (with the commit they were taken at), the diff's findings (new packages, TODOs, skipped tests, private addresses), the gates (`{main_branch}` resolved) and the database commands. A worker's names the plan (`show ID --plan` prints it) and where it may no longer hold; a planner's names an earlier plan to revise and a parked branch's work; `--evaluate` adds every report of the attempt, the diff stat and the merge resolutions to read. In the merge clone (with or without an ID) it is the merger's: the round, base and card, the conflicted files or the failed check with its tail and whether it passed on main alone, main's commits since the card's base, the criteria, the checks and the exact `merged` commands |
| `next [--count=1 --planning --json] [-v]` | Pull order, or `none: <reason>`; `--planning` the planning cards for planners; `-v` names why each card waits |
| `gates [ID]` | Runs main's gates (main's `config/kanban.php`) in the card's clone, or in the merge clone for the merger (in its container when its shell is there); `pass\|fail <command>` lines. A gate the branch adds runs only after the merge; `context` says when the branch changes that file |
| `upstream` | Pending package findings, `ID:logid Title` |
| `questions` | What waits for the owner, blocking first: the open questions of cards not done or dropped, then provisional decisions until answered (merged cards' too); `<ID>#<n>` with the card, the context and the options (recommended, taken); an older free-form question raw. Ends with `N open, M provisional`, the count `status` and `morning` print |
| `morning [--since=24h\|90m\|ISO]` | The brief, then since then: merged cards, cards blocked but not on a question, questions, discovered cards in backlog without criteria (they carry the filing card's `area:*`), and the agent runs `kanban run` logged (tokens, list-price cost, per merged card) |
| `validate [--fix]` | Schema and cross-card rules; `--fix` rewrites canonically, re-ids duplicates (one commit) |
| `doctor [--fix]` | `ok\|warn\|fail` lines, exit 1 on any fail; `--fix` re-runs attach and the install steps, then Claude Code needs a restart |
| `lease [--takeover --release]` | The orchestrator lease, not the merge lease `status` shows (15 min idle expiry; SessionEnd frees the ending session's, and a `kanban run` that outlives it takes it again; SessionStart hands it to a new session of the holder's transcript); `--takeover`/`--release` run from the main checkout |

## Write (main session or owner)

| Command | Does |
|---|---|
| `new BOARD "Title" [--type --priority --epic= --label=* --accept=* --depends=* --body= --body-file=- --stage=backlog\|planning]` | Create a card; the input is checked before an id exists (planning: the ready policy). `hint:` lines name an open card on the same area, a hub to fold into, and a body sentence or criterion over 25 words (references/planning.md) |
| `board BOARD ["Title"] [--order= --wip-doing=]` | Create or update a board (one board, `work`, is the norm) |
| `epic [SLUG "Title" --goal= --done-when=* --order=]` | Without a slug: every epic with its done count. With one: create or update `_epics/<slug>.json`; cards join with `epic=<slug>` |
| `set ID k=v… [--reason=]` | `title= priority= type= epic=slug labels=+a,-b depends_on=+ID accept+="…" accept[2]="…" accept-=3 accept=@- tick=1 untick=2 blocked="…"/"" body=@- note="…"`; one `@-` per run. A ready card whose criteria or body change goes back to planning. In a locked stage (doing, review, done) only `note= blocked= epic= tick= untick=`, and `accept[N]=` with `--reason` (logged, unticked, a review card back to doing), are taken (exit 3); `--force` (main) overrides; `new`'s `hint:` lines for what the run changed |
| `fold FROM… --into=ID` | Backlog, planning (no planner on them) or ready cards into one: bodies as `## Folded from` sections (an unanswered question the target already holds, kept once), criteria, labels and dependencies joined, the higher priority; FROM dropped, its dependents repointed; a ready target back to planning; one commit |
| `fold-boards [--into=work --dry-run]` | An older board (boards inside epic directories) onto one work board, version 3: each card's epic from its old directory (not `project`), decision cards into `decisions.md`, open questions onto the cards that waited on them; runs `Migrate` first, refuses while a card is in doing or review |
| `upstream file ID:logid [--new\|--comment=N]` · `upstream new "Title — body" [--new\|--comment=N]` · `upstream dismiss ID:logid --reason=` | With `KANBAN_UPSTREAM` on: a finding filed on the package's repository (`upstream.repo`, `[HOST/]OWNER/REPO`, github.com without a host; gh signed in to that host) after an issue search (a match exits 3), text that names the project refused; `new` files the main session's own finding, recorded on no card; or dismissed |
| `rebuild-branch ID` | A card in doing or review whose clone is on its branch and has no uncommitted change to a tracked file, from the clone as the card's agent (Guard's `--in`: its worker, when the stop gate names a merge; never while an evaluator runs) or by main from the main checkout with no live agent: the branch becomes one commit with the same files on the main it last merged (`merge-base`), logged `rebuilt`; an approval and a staged report are discarded |
| `answer ID[#n] OPTION [--note=]` | Any stage: `## Owner answer (date)` under that question (the option's text, the note; a free-form question needs `--note`). An open question: block cleared, then `promote`. A provisional decision answered differently: a card not yet at work loses its plan (a ready one goes back to planning); on one at work, prints that a follow-up card makes the change |
| `move ID STAGE [--reason= --force]` · `move ID --board=BOARD` | Transitions below; out of backlog it is `promote`; a board move is a `git mv` |
| `promote [ID…] [--auto]` | Backlog → planning by the ready policy, or → ready when the card's plan is current; `refused ID: R1 …` lines. `--auto` first sends ready cards without a current plan back to planning (`replanned ID`), then takes backlog cards whose dependencies are done until the ready cards waiting to start plus the planning cards a planner holds or may take reach `ready_buffer`, one card ahead per area (`skipped ID: area:x takes ACME-Y next (ready)`): an area runs one card at a time. A card it cannot write (an invalid card file) is `skipped ID: <error>` on stderr, the others go on, and the exit is that error's; a busy board lock ends it, exit 6 |
| `plan ID --plan-file=PATH\|-` | Main or owner, from the main checkout: a plan of your own for a card in planning no planner holds, checked like a planner's against main's HEAD (see "Plans"), recorded, the card to ready. The dependency rule is the planner's, not this one's |
| `import-house-docs [--decisions= --ideas= --dry-run --strict --remove-sources]` | decisions.md / ideas.md → decided and dropped entries into `decisions.md`, floated ideas as backlog spikes with a question; idempotent. `--remove-sources` then deletes the files when every entry is on the board and none warned |

## Card lifecycle (main session)

| Command | Does |
|---|---|
| `run [--once] [--until-attention --timeout=1500] [--drain]` | Main checkout, Linux, card containers, the four agent files. Under the orchestrating session's lease (`KANBAN_SESSION`, else `run:<host>`, which takes it over), every 15 s: runs the merge queue (below), launches the evaluator of a review card on its branch as it is, parks question cards in backlog, resumes doing cards' workers after merging main, and merges main before any worker takes a card the merge sent back (never into uncommitted changes: the worker commits them first, and a review card with them goes back to doing; its own session resumed), finishes this checkout's starts cut short after the claim before any new start (`start` again: a lost stack slot once one is free, a cleared block at once; another refusal blocks the card), moves planned cards to ready (`stop --to=ready`), parks a planner's question card in backlog, resumes a planner whose card has no current plan (its own session if it started under the card's claim, after `refresh`), starts nothing while `stack.max_stacks` is full (the idle line names it; a slot whose clone is gone and whose card is not at work is freed after 10 min, its stack down first), `promote --auto`, starts ready cards up to capacity and planners on the slots they leave, syncs the board when idle. Each agent is `claude -p --agent kanban-planner\|kanban-worker\|kanban-evaluator\|kanban-merger` (`--model` and `--effort` from `agents.<role>` in main's `config/kanban.php`, read at each launch and printed on the launch line as `(model, effort)`; `--permission-mode acceptEdits`, the routed-command allow rules, `agents.allowed_tools`), detached; `runs.jsonl` logs each run, `run.log` each line. A usage limit pauses launches 15 min; three runs without progress (a plan applied counts as progress), two rejects in a row since the card's start that fail the same criteria (`kanban run: rejected 2× on criterion N: <evidence>`, once per verdict, one verdict applied twice counting once: unblocked, it resumes), three send-backs by the merge since its start, or a failed `refresh`/`stop` (one refused while an agent of the card is live waits for it), block the card. The merge queue: one `finish` at a time per checkout, detached (`.git/laravel-house/merge/`), for this machine's next card (`finish` below), and no merge while main's `finish.check` is empty or its compose file is gone, read from main's `config/kanban.php` in each pass (one notice, naming a merge in flight here, which waits until then or `finish <ID> --abort`; a push in flight is settled all the same; a `finish` refused for it blocks no card). Exit 12 launches the card's merger in the merge clone, its eligibility checked again before every launch (a card that left the queue: `finish --abort`; three merger runs without a result block the card; a usage-limit pause aborts a merge whose merger is not at work, the card stays queued); 11 and 13 are logged; 10 raises each stderr line as `<ID> merged, then: …` and blocks nothing (for a card done before that `finish`, whose clone, stack or branch here it tidied: `<ID> done; its leftovers here: …`, once while it lasts); 1, 2, 4, 7 and 9 raise `finish`'s first stderr line (`<ID>: …`, or `<ID> merge failed: …` when it names no card) and retry after 5 min; 3 blocks the card while it is still queued. With no merge running or kept here (waiting to retry included), `finish --follow` brings the main checkout to origin's main at most every 120 s; what fails there, or before a merge's own failure, raises `main checkout …` once whatever commit it names, again only after a follow or merge here succeeded, and delays no merge. `--until-attention` returns with an `attention:` block (a card newly blocked without a question, a parked question, a card waiting on the owner, a failed merge, `main is red: …` once for each command and main commit (the queue's, which holds it, or an agent's), `a check timed out: …` once for each main commit, card and command, a card `promote --auto` could not write (once per message), more package findings, a pause, idle, an error) or after `--timeout`. `--drain` starts no new card and plans none (parked work, a branch an answer sent back, is in flight: it is planned and starts; a card whose last move was a `stop` is not) and returns once none is in flight and no merge runs here, a start of this checkout cut short included (an approved card the queue holds for a red main or a timed-out check, or cannot merge at all, is not in flight); `drain` makes every run drain until one has. One run per checkout (`run.lock`, freed however the run ends; `run.pid` names it): a second exits 3. It finishes only the starts its own checkout made (`starts/`). Exit 3 refused, 6 lease |
| `drain [--off]` | Main or owner. `run.drain`: a running `run` drains from its next pass, never cut short mid-merge (approved cards still merge), and so do the runs after it until one returns `drained`; `--off` removes it |
| `start ID [--force]` | A ready card for its worker (it needs a current plan), or a planning card for its planner (dependencies done; the card stays in planning, held). Claim (it records where the work goes), slot taken before the claim, a clone of main at `.claude/worktrees/<id without key>-<slug≤24>` (compose project `{app}-wt-` + that name) on `card/<id>-<slug≤40>` (a planner's clone of a parked branch: the branch as it is, its head recorded), deps copied, `.env`, `compose up -d --build` (no wait), for a planner the card's earlier plan in `.tmp/plan.md`; prints the Agent spawn line. Run again, it finishes a start cut short. A refusal names the cause: no current plan, the card ahead on its area (`area:x goes to ID first`), or the limit and its numbers (`no capacity (doing 5 + planning 1/6)`, `board work doing 2/2`, `review 6/6`; planners share `max_parallel` with workers, and the review limit stops only starts) |
| `refresh ID\|--all` | A card in doing or planning (`--all`: each with a clone here); one in review exits 3, since the merge queue merges main into approved work. Refuses while the card's agent is live, and while its clone has uncommitted changes to tracked files or a merge in progress (a merge would carry them into the merge commit; `--all` skips the card, exit 3). Merges this machine's main into the branch in the card's container (its stack brought up when it is down) and logs the round; a staged report or an older verdict no longer applies. Conflict → merge left in progress, exit 5, prints `SendMessage: …`. A card a planner holds: its fresh planning branch moves to main's head (a planner commits nothing); a parked branch stays as it is. Prints the next spawn line |
| `wait [ID…] [--timeout=90]` | Main only. Blocks until the card's agent has stopped (its plan, report or verdict applied by then) or its stop was refused; no ID: until any card at work with a live agent settles. Prints each settled card's line. Exit 75 after the timeout |
| `finish [ID] [--abort --follow --no-rebuild]` | Main or owner: the merge queue's step for one card, which `kanban run` runs detached. One per checkout (`merge.run.lock`; a second exits 11), resumed from `.git/laravel-house/merge.json` where the last one stopped. Without an ID: the merge in flight here, else this machine's next card. Refused (exit 3) while main's `finish.check` is empty or stacks are off, also when following origin's main makes them so; a push in flight is settled all the same. The card: review, approved, unblocked, its branch on this machine, no live agent but its merger, not held by a red main or a timed-out check, and its turn: approval order, where another machine's card goes first until it has headed the queue 15 min as this machine sees it (exit 11). Then: the approved head pinned (a branch past it: approval cleared, exit 13); the merge lease taken on `kanban.json`, won by the push that lands (held elsewhere: exit 11; one whose beat stood still 15 min here is taken over, and a push of its that landed marks its card done in the same commit), and kept beating every 2 min by a detached beater while this `finish` or the card's merger lives; the merge clone `.claude/worktrees/_merge` and its stack up (`{app}-merge-<hash>`, outside `max_stacks` and the resource checks); origin's main fetched and the main checkout fast-forwarded to it with its after-steps (a failure is a stderr line, and exit 10 once the card merges), then pinned as the base (no remote: local main, checked out and clean, with no file git does not track where the card adds one; else exit 11, `main checkout not moved: …`, which `kanban run` raises); the merge made in main (main first parent, `<ID>: <title>`); in the merge stack, from main's config: the `finish.install` commands of changed lockfiles, the gates, each `finish.check` command (each under its timeout, 30 min by default). A conflict (in `.claude/` or `config/kanban.php`: the card is blocked, exit 3) or a red check: exit 12, the merger's turn; its merge stack is brought up first when it is down. A `finish.check` command not found in the container (exit 126, 127) is exit 7 when the base lacks it too (the merge let go, uncounted), else a red check. A merge stack that fails (exit 7) while the merge holds its merger's work or waits on its merger keeps the merge and its lease for the next `finish`; the third failure in a phase blocks the card, as does the third merge stack that came up on the base and fails on the merged tree's docker files. A `finish.check` command that also fails on the base alone (rerun when the card changed no lockfile, docker or compose file): main is red, a `merge` entry `result: main` (command, base) on the card, exit 13, and the queue holds every approved card until origin's main moves; no card is filed. A gate, install or `finish.check` command that runs past its timeout is ended with every process it started and runs nowhere again: a `merge` entry `result: timeout` (step, command, seconds, base, head) on the card, exit 13, and the queue holds every approved card until origin's main moves or the card leaves the queue at that head (blocked, or approved at another head); no merger. A conflict git resolves as it was resolved before (rerere) is checked by the merger gate first (refused: forgotten, the merger's turn). Green: main pushed as a fast-forward, never forced (rejected: merged again on the new main, at most 3 rounds; another push error origin answered: the lease given back, exit 9, the third blocks the card), the card done, its stack, clone, branch and pins removed, the main checkout fast-forwarded with installs, a rebuild of main's stack (`--no-rebuild` skips it) and `kanban.migrate` and `finish.after` in it (no remote: these after-steps run before the card is done, so a `finish` killed during them goes on with them). Prints `merged <ID> into <main> <sha7>`, then `<ID> review→done`. `--abort`: the merger stopped, the lease given back, the merge clone reset; the card stays queued (exit 11 while a `finish` runs here, which `stop` ends when `kanban run` started it, or mid-push). `--follow`: only the main checkout to origin's main, with its after-steps |
| `stop ID --to=ready\|backlog\|dropped [--keep-branch --force --reason=]` | A card being merged here: its merge run and merger are ended and the merge lease given back first (`finish --abort`); refused while main is pushed, or while a `finish` that `kanban run` did not start runs here (exit 11; each check ends within its timeout, 30 min by default). The card's `kanban run` agent ended first, with every command it runs (SIGTERM to its process group and to the group of each process under it, which takes in Claude Code's shells, each in a session of its own; SIGKILL after 10 s; only a run whose command line names its session; a plan its planner staged and is not yet applied refuses the stop without `--force`); from then until the card's stage changes, `stopping/<ID>` keeps `kanban run` from launching, resuming or restarting anything for it, and an agent launched as the mark appeared is ended at once. A stop that fails after the agent ended keeps the mark, which expires after 10 min, and says so. Then stack down, slot freed, clone removed (dirty, checked before the agent ends and again after, in review only a tracked change, or started on another machine → refused without `--force`; untracked leftovers of a review card are named and go with it); a branch with commits is parked; the next `start` reuses it with main merged in (a conflict is left in progress for the worker, as `refresh` leaves one); `--to=ready` clears a block that is not a question, and sends a card whose plan does not cover the work on its branch on to planning, where its planner plans the rest. A card a planner holds: `--to=ready` once its planner's plan is applied and current (refused before anything comes down otherwise), `--to=backlog\|dropped` takes the planner off; its clone holds nothing to keep, a fresh planning branch is deleted, a parked one stays parked at its recorded head |
| `stack [ID\|PATH\|_merge] create\|up\|down\|status\|wait\|reload\|logs\|url` · `stack ID exec -- CMD` · `stack list` · `stack gc [--force]` | Per-card stack; `_merge` names the merge stack, whose clone only the merge queue makes (`create` refuses it). `wait` exits 75 after 110 s, 7 with the log tail when the service's container exited or restarted, 5 while a merge of main is in progress (it only starts the stopped container as it was), and otherwise recreates a stack whose docker files, compose file or lockfiles (at any depth) changed since it came up, as `gates`, `report` and a clean `refresh` do; `reload` recreates it. `gc`: this repository's slots whose clone is gone, plus idle isolated-agent worktrees; `--force` also its unregistered `{app}-wt-*` and `{app}-merge-*` projects |
| `apply [ID\|--all]` | Apply staged reports/verdicts whose agent is gone; retry hook payloads left in `inbox/` |
| `sync` · `publish` · `sweep` · `attach` | Pull/push `kanban`; push `kanban`, and `main` when it is ahead of origin's (commits made outside the queue; never a merge, diverged exits 3); commit journaled UI writes and prune old runtime files (`--reclaim` also removes idle isolated-agent worktrees; SessionStart runs both); check out the board on this machine |

## Agents

| Command | Who | Does |
|---|---|---|
| `plan ID [--plan-file=.tmp/plan.md --status=ready\|blocked --reason= --question-file=PATH --discovered=* --upstream=* --note=]` | planner, own card | The plan checked (see "Plans") against the clone's HEAD and staged with the hash of the card it covers; applied at Stop (SubagentStop for a subagent): the plan and a `planned` entry on the card, which stays in planning, held, until `stop --to=ready`. Refused at apply when the card's criteria or body changed since: the planner revises it. `--status=blocked` with a reason or an Open question; Provisional decisions go with a ready plan, as does a question whose answer changes none of the card's criteria |
| `report ID --status=review\|blocked [--tick=N* --summary= --summary-file=- --verified="cmd → result"* --discovered="bug: Title — body"* --upstream="Title — body"* --reason= --note= --question-file=PATH]` | worker, own card | `review` runs the gates first and refuses on a failure or a conflict marker; its `--verified` cites the whole suite run after the last commit (`<cmd> @<sha> → N passed`), which the evaluator does not repeat at that head; staged; applied at SubagentStop (Stop for a `kanban run` agent). `--question-file`: Provisional decisions (review) or an Open question (blocked; the block becomes `question: …`), each in plain words with an `Example:` line, checked when staged, appended to the body once. A worker may report again on its card in review |
| `verdict ID approve\|reject --check=N:pass\|fail:"evidence"* [--issue=* --discovered=* --upstream=* --note=]` | evaluator, own card | Every criterion needs one check; approve needs all pass and no issues; discovered items never decide it. Each evidence and issue is at most 2 000 characters (exit 2: cut prose, keep the facts) and reaches the worker whole |
| `merged ID resolved\|fixed\|back\|main --note=…\|--note-file=-` | merger, in the merge clone | Stages the merge's result, applied at Stop: `resolved` answers a conflict (HEAD is the merge of `refs/merge/base` and `refs/merge/card`, concluded and committed), `fixed` a failed check (commits on top of the checked head, one parent each: no merge commit), `back` sends the card to its worker, `main` says the failure is on main and holds the queue until main moves (refused when the command passed on main alone). Every result needs a note of at most 2000 characters. `resolved` and `fixed` are refused while the merge clone holds a change no commit has, a new file not added included, and when the merger's changes touch `config/kanban.php` or `.claude/`, delete a file under a `tests/` directory or add a skipped test. Prints `staged merge result <result> for <ID>: applied when you stop`. Besides it, the merger runs only `context`, `show` and `gates` |
| `gates` · `migrations [--base=]` · `data-ids [--base=]` · `stack status\|wait\|logs\|up\|reload` | planner, worker, evaluator; the merger `gates` only, in the merge clone | Own card only; never `down` |

A file an agent's command names (`--summary-file`, `--question-file`, `--plan-file`, `--note-file`) is a path relative
to its own clone; `-` reads stdin.

## Plans

`plan` refuses, naming what to fix, a plan without `## Files`, `## Steps` (numbered) or `## Criteria`, or over 20 000
characters. A Files line is ``- create|change|delete|read `path` — why``: one path from the repository root, outside
`.git` and `.claude/worktrees`, looked up in git (`change`, `delete` and `read` in the commit the plan is made on,
`create` not). Criteria holds one `- N: …` line per criterion of the card, each naming its proof in a code span.

The plan pins contracts and names files to copy from; the worker writes the code. `plan` stages a plan that runs long,
writes the code or runs docker with a `hint:` line for each: over 8 000 characters or 20 steps, a code block over 12
lines, more than 40 lines of code blocks in all, an inline code span over 200 characters, a `docker compose`, `exec` or
`run` command (the worker's shell is inside its container).

A plan is current while its `planned` entry's hash is the card's content (the criteria and the body, without the
question sections and an answer that confirms what a Provisional decision took), and for a card with a parked branch,
while it came after the card's last start. Any other answer, an answer's note and text added after a question change
it. Only a card with a current plan starts; `promote --auto` sends a ready card without one back to planning.

## Transitions

| Move | Through |
|---|---|
| backlog → planning | owner or main (`promote`, `move`): the ready policy (R1 an `area:*` label, R2 title, R3 body, R4 1–24 criteria, R5 deps valid, R6 not blocked, R7 in backlog); a card with a current plan goes to ready |
| planning → ready | a plan: its planner's (`stop --to=ready`), or the owner's or main's (`plan`) |
| ready → planning | owner or main (`move`), an edit of the criteria or body, an `answer` other than a confirmation of what the plan took, `promote --auto` for a card without a current plan |
| planning/ready → backlog | owner or main (`move`); a card a planner holds: `stop` |
| ready → doing | `start` only |
| doing → review | an applied `report --status=review` only |
| review → doing | reject verdict, the merge queue's send-back (`via merge`: the merger's `back`, still red after 3 merger rounds, leftover conflict markers), or owner "send back" with a note |
| review → done | the merge queue (`finish`) only |
| doing/review → ready/backlog/dropped | `stop` only |
| doing/review → planning | `stop --to=ready` of a card whose plan does not cover the work on its branch |
| any → dropped | reason required; `fold` drops with "folded into ID" |

Pull order: capacity = min(max_parallel − doing and planning held on this host, Σ boards (wip.doing − doing)), 0 when
review ≥ wip.review; candidates ready, with a current plan, unblocked, unclaimed, deps satisfied, no shared `area:*`
label with doing/review; sorted parked work first (`work.parked_branch`: work in flight), then priority, epic order,
board order, oldest in ready, id; `urgent` may exceed capacity by 1, one urgent card at work at a time, a planner's
included. A card in review holds its area until it merges.
Planners take the slots of `max_parallel` that workers leave (the review limit does not stop them): planning cards
no planner holds, unblocked, on an area, deps done, in the same order; a busy area does not hold them back.

Limits: title 1–120 characters, body ≤ 20000, criteria 1–24 of ≤ 500, labels ≤ 10, dependencies ≤ 20, blocked ≤ 500,
report summary ≤ 2000 (longer is cut, with a warning). Criterion ids never renumber: `accept-=2 accept-=3` removes
two, `accept=@-` replaces them all.

## SubagentStop (what the hook does)

- Worker without a report since its last start → blocked with the exact `report` command; after 3 blocks the card
  gets `blocked: worker stopped without report` and the stop is allowed. A planner without a plan the same, with the
  `plan` command and `planner stopped without a plan`.
- Planner: the staged plan is applied once per content hash (the plan and its `planned` entry, or the block; questions
  into the body; discovered cards); refused, and the stop with it, when the card changed since it was staged.
- A report is refused on a dirty worktree, a blocked one too (work left uncommitted would keep main out later), and
  a review one while a merge of main is in progress; a blocked one is taken then, the merge left for the answer.
  `--status=review` also on zero commits beyond base, a failing `gates.report` command, or one of the branch's own
  merges of main carrying changes neither side had (a file that differs from git's own merge of the parents and was no
  conflict there): `rebuild-branch` makes the branch one commit with the same files.
- Applied under the lock, once per content hash: review/blocked, ticks, `work.head`, agent unbound. A staged plan, report
  or verdict names the card's last entry of its kind, so the same text staged again after that one landed (a block
  cleared, a later round) is applied again.
- Evaluator: approve (verdict head = branch head) → `work.approved` (`{head, at}`), which queues the card for the
  merge in approval order; reject → doing, failed criteria unticked. A
  verdict older than the card's last round is dropped (`verdict_superseded`); one on a card that left review files its
  discovered items only.
- Merger without a result while its merge still waits on one → blocked with the exact `merged` command; after 3 blocks
  the card gets `blocked: merger stopped without a result`. A staged result is applied once per content hash, by
  `merger`: `resolved` and `fixed` are logged and the next `finish` runs the checks again; `back` sends the card to
  doing via `merge` with the note as its reason (the worker's context shows it) and clears the approval; `main` ends
  the merge with a `merge` entry `result: main` (command, base), gives the lease back and holds the queue until origin's
  main moves; `kanban run` raises it. It files no card. The merger gate (as `merged`) runs again; a result
  for a merge that is over (another round, the lease gone, the card moved) is logged `merge_moot` and changes nothing.
- Either agent's `--discovered` items become cards in the backlog of the card's board, label `discovered`, unless a
  card with the same title is open or the card filed it before. `--upstream` findings become log entries.
- `--discovered='main: <command> — what fails'` is a failure already on main, never a card: a `main_red` entry
  {command, base, body} in the agent's own write, once for each command and main's tip as this machine knows it. It
  holds nothing; `kanban run` raises it once for each (command, base). The command ends at the first em or en dash,
  since ` -- ` is shell syntax; one in backticks ends at its closing backtick, and any separator starts the body
  (single-quote that item in the shell). Any other item splits at its first em dash, en dash or ` -- `. While origin's
  main is still its base, `context` lists it, and the queue's own finds, to workers, evaluators and the merger as
  `failing on main already: …`, and the brief (`status`, `morning`, SessionStart) as `main is red: …`.
- A refusal is kept for the agent: `context` shows it, the failing gate first.

## Recovery

| Failure | Do |
|---|---|
| Hook error | The PreToolUse hook never blocks and ignores its own errors. SubagentStop writes `inbox/` first: `apply --all` retries. `doctor` checks paths |
| UI and CLI write at once | Same lock; stale rev → 409 in the UI; every write is one commit |
| Teammate without the merge driver | `attach` / `doctor --fix` configure it; SessionStart verifies |
| Container git unusable (UI) | Writes go to the journal; the next host write, `sweep` or `sync` commits them |
| No stack slot / resources | Exit 7 names it: `stack gc`, merge or stop a card, or widen the Docker address pools |
| Main behind merged migrations | Before each merge and after its push, `finish` fast-forwards the main checkout, installs changed lockfiles, rebuilds main's stack when lockfiles, docker files or the compose file changed (`build` while it serves, then `up --force-recreate --wait`; `--no-rebuild` skips it), and runs `kanban.migrate` and `finish.after` in it; `kanban run` runs `finish --follow` on every machine with no merge running. A failed step is exit 10; under `kanban run`, an attention line |
| A merge that stays stuck | `status` names the merge lease and its holder. Here: `stack _merge logs`; once no `finish` runs here (each check ends within its timeout, 30 min by default), `finish <ID> --abort` gives the lease back, the card stays queued; `stop <ID> --to=…` ends one `kanban run` started first. A machine that died holding it: other machines take it over once its beat stood still 15 min; a push of its that landed marks the card done, and the card's own machine tidies its clone |
| Board version 1 | Every board command refuses; the owner runs `/implement-kanban` (`fold-boards`). `sync`, `doctor`, `attach` and `kanban:install` still run |
| A card deleted on origin, edited here | Sync keeps the local copy in `.git/laravel-house/displaced/`; `doctor` lists it |

## Driving the board by hand

Only where `kanban run` cannot stay alive (a cloud session, no `setsid`): the main session drives the board itself, with
background subagents.

### Keeping agents busy

- Merge approved cards first (`finish`): cards in review hold stacks and areas.
- Planning and ready hold work for every free slot: `promote --auto` fills planning, and the slots no ready card can
  take go to planners (`next --planning`).
- Every question for the owner goes out early and in one batch, never one at a time as agents hit them.
- `next` returns none while slots are free: `next -v` says why each ready card waits. With nothing to start or plan,
  spawn read-only audit agents (no isolation, no card), one per area, that check the code against its `CLAUDE.md` rules
  and return findings to card.
- "Stop": ask once whether the owner means **drain** (start nothing new; keep evaluating, merging and resending
  rejects; then publish and report) or **stop all** (no spawns at all), unless the words already say.

### The loop

1. **Orient.** `status`: WIP, cards in flight with their agents, blocked cards, questions, next, checks.
   - A `checks:` item not ok → `doctor` (`doctor --fix` for wiring) before starting work.
   - Cards in doing with `no agent`, or a `stopped`/`stale` agent → "Recover" first.
2. **Fill the pipeline.** `promote --auto`: ready cards without a current plan back to planning, then backlog cards
   whose dependencies are done into planning, up to `ready_buffer`. Refusals (no criteria, no area, an open question)
   go into the summary.
3. **Pick.** `next --count=<free capacity>`, then for the slots left `next --planning --count=<n>`; `none: …` → step 5.
4. **Start each.** `start <ID>` prints the clone, branch, stack and, last, the spawn line, a worker's for a ready card
   and a planner's for a planning card:
   `Agent(subagent_type="kanban-worker", description="…", prompt="Card <ID>. Worktree <path>")`.
   Spawn exactly that, in the background, unchanged, without `name` or `isolation`, and one kanban spawn per message:
   the agent binds to its card from that spawn when it starts. Context
   for the agent goes on the card (`set <ID> note=`), never into the prompt.
   - exit 3 refused: skip the card. exit 8 claim lost: `next` again, without it.
   - exit 9 remote unreachable: nothing was claimed; wait, report it, never set `KANBAN_SYNC=off` yourself.
   - exit 7 or 1 after the claim: the card stays in doing (or planning), blocked; fix the cause, then `start <ID>` again
     resumes it.
5. **Wait.** An agent ends with a hand-back message, then stops, and its stop applies the report or verdict. On a
   hand-back, run `wait <ID>`: it returns once that is done, with the card's line (`stop refused`: the agent works on;
   its next hand-back comes later). Then act on the card's state. A task notification may come late or start no turn:
   never wait for one. While agents run and nothing else is to do, keep one `wait --timeout=1800` running in the
   background (`run_in_background`): it ends when any agent settles, so a lost hand-back costs nothing. After acting,
   start it again. Never poll in a loop of your own.
6. **Planner finished** (`wait <ID>` printed the card still in planning). Its plan applied: `stop <ID> --to=ready` takes
   its clone and stack down and moves it to ready. Blocked on a question: `stop <ID> --to=backlog`, into the owner
   batch. Blocked otherwise (a criterion that cannot be done as written, a card too big): fix the card, `set <ID>
   blocked=`, and spawn a planner again with the line `show <ID>` prints.
7. **Worker finished, card in review** (`wait <ID>` printed review). `show <ID>` prints the evaluator's spawn line:
   spawn it. The evaluator judges the branch as it is; main is merged in by the merge queue, never by `refresh`.
8. **Worker blocked.** `show <ID>` has the reason. A question: `stop <ID> --to=backlog`, with the question on the card
   (see below), and into the owner batch. Anything else: fix the cause, or leave it blocked for the owner.
9. **Approved.** `finish` merges this machine's next approved card through the merge queue: the merge clone and its
   stack, the checks, the push of main, the card done and torn down, and the main checkout following main. That can
   take as long as the suite: run it in the background (`run_in_background`) and act on its exit when it ends. Run it
   again for the next card.
   - exit 12: a conflict or a failed check. Spawn the printed `merger:` line exactly, in the background, one kanban
     spawn per message; when the merger stops and its result is applied (`wait <ID>`), run `finish <ID>` again.
   - exit 13: the card left the queue. Back in doing: `refresh <ID>` (exit 5 leaves main's conflict for the worker),
     then spawn a worker with the line `show <ID>` prints (its context holds the merge's note). Its approval cleared:
     spawn the evaluator again. Main red: the attention tells you; fix main, `publish`, and the queue goes on. A check
     that timed out: the attention names both fixes (more time on main, or the card blocked).
   - exit 11: another machine merges, another card goes first, or an agent of the card is still live; try later.
     `main checkout not moved: …` (no remote): fix the main checkout with the owner
     (gotchas.md, "Nothing merges on a board without a remote"); retrying alone never helps.
   - exit 3: the message names it (`finish.check` empty: set it with the owner; a conflict in `.claude/` or
     `config/kanban.php` blocks the card for the owner: gotchas.md, "A merge blocks the card").
   - exit 10: see Exit codes.
   - exit 6: another session holds the lease and nothing merged (see Ground rules).
   - exit 7, 9: read the message; retry later.
10. **Rejected.** The card is back in doing; `show <ID>` prints the `SendMessage` text for its worker, or spawn a
    fresh worker with the line `show <ID>` prints.
11. **Repeat** 2–10 while there is capacity and work to plan or start.
12. **Publish** at the end: `publish` pushes `kanban` (each merge already pushed `main`). Never force-push.
13. **One message to the owner:** done (ids, titles, merge shas), in flight, the questions in one batch, refused
    promotions, what you decided on your own, and pending package findings. Facts only.
