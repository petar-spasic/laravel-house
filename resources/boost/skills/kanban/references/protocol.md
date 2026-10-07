# Kanban protocol reference

Every command runs as `vendor/bin/kanban <command>` (no app boot) or `php artisan kanban:<command>`. Output is plain
text, one fact per line; errors go to stderr. Card ids accept a unique prefix of ≥ 3 characters, any case,
`I`/`L` → `1`, `O` → `0`.

## Exit codes

| Code | Meaning | React |
|---|---|---|
| 0 | ok | — |
| 1 | unexpected | read stderr; `doctor`; retry once |
| 2 | invalid input | fix the flags or values |
| 3 | refused (policy or precondition) | the message names the rule; fix it or pick another card |
| 4 | not found or ambiguous | longer id prefix; `attach` when the board is missing |
| 5 | git conflict | `refresh`, hand the conflict to the worker, or resolve on main |
| 6 | lock or lease | another writer or orchestrator; wait, or `lease --takeover` (your own previous session, or with the owner's OK) |
| 7 | stack | `stack <ID> logs`, `doctor`; fix, then `stack <ID> up` |
| 8 | claim lost | another machine took the card; `next`. A card that left `ready` is exit 3. A scheduler skips that card for the rest of its run |
| 9 | remote | network down, or a push rejected 3 times in a row: transient, retry later with backoff; never force-push. A claim that exits 9 left no claim commit behind here. A `start` whose push reached origin all the same (the remote failed before it could say so) is finished by running `start` again, or by `kanban run` |
| 75 | stack still starting | run `stack wait` again |

## Actors

- `main`: `KANBAN_SESSION` set (SessionStart exports it). `owner`: a terminal without it, or the UI.
- `planner` / `worker` / `evaluator`: bound to their card when they start (SubagentStart claims the main session's spawn
  record); `plan` / `report` / `verdict` also require the cwd to be the card's clone, where an agent's shell starts. An agent runs
  `vendor/bin/kanban` as a command of its own (on this machine); its other commands run in the card's container.
- `--force` is main-only and logged.
- A log entry may carry `who`: the person at that keyboard (`KANBAN_USER`, else git `user.name`, else the name in an explicit `KANBAN_GIT_AUTHOR`; none known: no key). `by` stays the role, `who` is shown beside it (`owner (Ana)`, `worker (Ana)`) and is never used for claims, notes, locks or merges. An entry written by an agent carries the person whose machine ran it.

## Read (anyone)

| Command | Does |
|---|---|
| `status [--json]` | The brief: branch, unpushed, WIP, doing/review with agents and URLs, blocked, questions, why ready cards wait, hubs, rule files over 40 KB, pending upstream findings, checks |
| `list [--board= --epic= --stage= --type= --label= --all --json]` | Default: planning, ready, doing, review, plus blocked anywhere |
| `show ID [--json --log=10 --plan]` | Header, body, criteria, the plan's size and commit, deps with stages, claim, work, agent state, log; at work the agent's spawn line, after a reject the message for the worker. `--plan` prints only the plan (exit 4 without one) |
| `context [ID] [--evaluate]` | For agents; card from the cwd: notes (with the commit they were taken at), the diff's findings (new packages, TODOs, skipped tests, private addresses), the gates (`{main_branch}` resolved) and the database commands. A worker's names the plan (`show ID --plan` prints it) and where it may no longer hold; a planner's names an earlier plan to revise and a parked branch's work; `--evaluate` adds every report of the attempt, the diff stat and the merge resolutions to read |
| `next [--count=1 --planning --json] [-v]` | Pull order, or `none: <reason>`; `--planning` the planning cards for planners; `-v` names why each card waits |
| `gates [ID]` | Runs every gate in the card's clone (in its container when its shell is there); `pass\|fail <command>` lines |
| `upstream` | Pending package findings, `ID:logid Title` |
| `questions` | What waits for the owner, blocking first: the open questions of cards not done or dropped, then provisional decisions until answered (merged cards' too); `<ID>#<n>` with the card, the context and the options (recommended, taken); an older free-form question raw. Ends with `N open, M provisional`, the count `status` and `morning` print |
| `morning [--since=24h\|90m\|ISO]` | The brief, then since then: merged cards, cards blocked but not on a question, questions, discovered cards in backlog without criteria (they carry the filing card's `area:*`), and the agent runs `kanban run` logged (tokens, list-price cost, per merged card) |
| `validate [--fix]` | Schema and cross-card rules; `--fix` rewrites canonically, re-ids duplicates (one commit) |
| `doctor [--fix]` | `ok\|warn\|fail` lines, exit 1 on any fail; `--fix` re-runs attach and the install steps, then Claude Code needs a restart |
| `lease [--takeover --release]` | The orchestrator lease (15 min idle expiry; SessionEnd frees the ending session's, and a `kanban run` that outlives it takes it again; SessionStart hands it to a new session of the holder's transcript); `--takeover`/`--release` run from the main checkout |

## Write (main session or owner)

| Command | Does |
|---|---|
| `new BOARD "Title" [--type --priority --epic= --label=* --accept=* --depends=* --body= --body-file=- --stage=backlog\|planning]` | Create a card; the input is checked before an id exists (planning: the ready policy). `hint:` lines name an open card on the same area and a hub to fold into |
| `board BOARD ["Title"] [--order= --wip-doing=]` | Create or update a board (one board, `work`, is the norm) |
| `epic [SLUG "Title" --goal= --done-when=* --order=]` | Without a slug: every epic with its done count. With one: create or update `_epics/<slug>.json`; cards join with `epic=<slug>` |
| `set ID k=v… [--reason=]` | `title= priority= type= epic=slug labels=+a,-b depends_on=+ID accept+="…" accept[2]="…" accept-=3 accept=@- tick=1 untick=2 blocked="…"/"" body=@- note="…"`; one `@-` per run. A ready card whose criteria or body change goes back to planning. In a locked stage (doing, review, done) only `note= blocked= epic= tick= untick=`, and `accept[N]=` with `--reason` (logged, unticked, a review card back to doing), are taken (exit 3); `--force` (main) overrides |
| `fold FROM… --into=ID` | Backlog, planning (no planner on them) or ready cards into one: bodies as `## Folded from` sections (an unanswered question the target already holds, kept once), criteria, labels and dependencies joined, the higher priority; FROM dropped, its dependents repointed; a ready target back to planning; one commit |
| `fold-boards [--into=work --dry-run]` | An older board (boards inside epic directories) onto one work board, version 3: each card's epic from its old directory (not `project`), decision cards into `decisions.md`, open questions onto the cards that waited on them; runs `Migrate` first, refuses while a card is in doing or review |
| `upstream file ID:logid [--new\|--comment=N]` · `upstream new "Title — body" [--new\|--comment=N]` · `upstream dismiss ID:logid --reason=` | With `KANBAN_UPSTREAM` on: a finding filed on the package's repository (`upstream.repo`, `[HOST/]OWNER/REPO`, github.com without a host; gh signed in to that host) after an issue search (a match exits 3), text that names the project refused; `new` files the main session's own finding, recorded on no card; or dismissed |
| `rebuild-branch ID` | A card in doing or review whose clone is on its branch and has no uncommitted change to a tracked file, from the clone as the card's agent (Guard's `--in`: its worker, when the stop gate names a merge; never while an evaluator runs) or by main from the main checkout with no live agent: the branch becomes one commit with the same files on the main it last merged (`merge-base`), logged `rebuilt`; an approval and a staged report are discarded |
| `allow-steering ID PATH…` | Main or owner, any stage. Logs the owner's approval of the card's changes to those files that steer the agents or git (a directory ends in `/`): given before the branch changes a file it covers any change, given after it the file as it is then (a later change asks again); a card at work is approved only where its branch is. Context shows what is approved and what still needs it |
| `answer ID[#n] OPTION [--note=]` | Any stage: `## Owner answer (date)` under that question (the option's text, the note; a free-form question needs `--note`). An open question: block cleared, then `promote`. A provisional decision answered differently: a card not yet at work loses its plan (a ready one goes back to planning); on one at work, prints that a follow-up card makes the change |
| `move ID STAGE [--reason= --force]` · `move ID --board=BOARD` | Transitions below; out of backlog it is `promote`; a board move is a `git mv` |
| `promote [ID…] [--auto]` | Backlog → planning by the ready policy, or → ready when the card's plan is current; `refused ID: R1 …` lines. `--auto` first sends ready cards without a current plan back to planning (`replanned ID`), then takes backlog cards whose dependencies are done until the ready cards waiting to start plus the planning cards a planner holds or may take reach `ready_buffer`, one card ahead per area (`skipped ID: area:x takes ACME-Y next (ready)`): an area runs one card at a time |
| `plan ID --plan-file=PATH\|-` | Main or owner, from the main checkout: a plan of your own for a card in planning no planner holds, checked like a planner's against main's HEAD (see "Plans"), recorded, the card to ready. The dependency rule is the planner's, not this one's |
| `import-house-docs [--decisions= --ideas= --dry-run --strict --remove-sources]` | decisions.md / ideas.md → decided and dropped entries into `decisions.md`, floated ideas as backlog spikes with a question; idempotent. `--remove-sources` then deletes the files when every entry is on the board and none warned |

## Card lifecycle (main session)

| Command | Does |
|---|---|
| `run [--once] [--until-attention --timeout=1500] [--drain]` | Main checkout, Linux, card containers, the three agent files. Under the orchestrating session's lease (`KANBAN_SESSION`, else `run:<host>`, which takes it over), every 15 s: finishes the oldest approval (main moved → refresh, evaluator; unapproved steering files → an owner question, the card waits in review), refreshes review cards and launches their evaluator, parks question cards in backlog, resumes doing cards' workers after merging main (never into uncommitted changes: the worker commits them first, and a review card with them goes back to doing; its own session resumed), finishes this checkout's starts cut short after the claim before any new start (`start` again: a lost stack slot once one is free, a cleared block at once; another refusal blocks the card), moves planned cards to ready (`stop --to=ready`), parks a planner's question card in backlog, resumes a planner whose card has no current plan (its own session if it started under the card's claim, after `refresh`), starts nothing while `stack.max_stacks` is full (the idle line names it; a slot whose clone is gone and whose card is not at work is freed after 10 min, its stack down first), `promote --auto`, starts ready cards up to capacity and planners on the slots they leave, publishes when idle. Each agent is `claude -p --agent kanban-planner\|kanban-worker\|kanban-evaluator` (`--permission-mode acceptEdits`, the routed-command allow rules, `agents.allowed_tools`), detached; `runs.jsonl` logs each run, `run.log` each line. A usage limit pauses launches 15 min; three runs without progress (a plan applied counts as progress), or a failed `finish`/`refresh`/`stop`, block the card. `--until-attention` returns with an `attention:` block (a card newly blocked without a question, a parked question, a card waiting on the owner, a red main, more package findings, a pause, idle, an error) or after `--timeout`. `--drain` starts no new card and plans none (parked work, a branch an answer sent back, is in flight: it is planned and starts) and returns once none is in flight, a start of this checkout cut short included; `drain` makes every run drain until one has. One run per checkout (`run.lock`, freed however the run ends; `run.pid` names it): a second exits 3. It finishes only the starts its own checkout made (`starts/`). Exit 3 refused, 6 lease |
| `drain [--off]` | Main or owner. `run.drain`: a running `run` drains from its next pass, never cut short mid-merge, and so do the runs after it until one returns `drained`; `--off` removes it |
| `start ID [--force]` | A ready card for its worker (it needs a current plan), or a planning card for its planner (dependencies done; the card stays in planning, held). Claim (it records where the work goes), slot taken before the claim, a clone of main at `.claude/worktrees/<id without key>-<slug≤24>` (compose project `{app}-wt-` + that name) on `card/<id>-<slug≤40>` (a planner's clone of a parked branch: the branch as it is, its head recorded), deps copied, `.env`, `compose up -d --build` (no wait), for a planner the card's earlier plan in `.tmp/plan.md`; prints the Agent spawn line. Run again, it finishes a start cut short. A refusal names the cause: no current plan, the card ahead on its area (`area:x goes to ID first`), or the limit and its numbers (`no capacity (doing 5 + planning 1/6)`, `board work doing 2/2`, `review 6/6`; planners share `max_parallel` with workers, and the review limit stops only starts) |
| `refresh ID\|--all` | Refuses while the card's agent is live, and while its clone has uncommitted changes to tracked files or a merge in progress (a merge would carry them into the merge commit; `--all` skips the card, exit 3). Merges main into the branch and logs the round; a staged report or an older verdict no longer applies. Moved head → approval cleared. Conflict → card to doing, merge left in progress, exit 5, prints `SendMessage: …`. A card a planner holds: its fresh planning branch moves to main's head (a planner commits nothing); a parked branch stays as it is. Prints the next spawn line |
| `wait [ID…] [--timeout=90]` | Main only. Blocks until the card's agent has stopped (its plan, report or verdict applied by then) or its stop was refused; no ID: until any card at work with a live agent settles. Prints each settled card's line. Exit 75 after the timeout |
| `finish ID [--force --ask]` | A branch that changes kanban's own files (`.claude/`, `config/kanban.php`, hooks, `.gitattributes`) merges once the owner approved each (`allow-steering`, or answer 1 to the Open question `--ask` puts on the card and blocks it with, which approves the files as they are; `kanban run` passes `--ask`; answer 2 sends the card back to doing; a `Steering:` line anywhere else approves nothing, and a question file refuses one). `--force` only with the owner: a red main, or those files unapproved. Needs review + approval of the current head (a main that moved only `finish.overlap_ignore` files keeps it) + no uncommitted change to a tracked file in the clone (untracked leftovers go with it, named) + no live agent + main green. Merges `--no-ff`; stack down, clone and branch removed; then installs changed lockfiles, runs `kanban.migrate`, `finish.after` (read after the merge) and `finish.check` (red marks main and holds the next finish), rebuilds main's stack, pushes main every `publish.every` merges |
| `stop ID --to=ready\|backlog\|dropped [--keep-branch --force --reason=]` | Stack down, slot freed, clone removed (dirty, in review only a tracked change, or started on another machine → refused without `--force`; untracked leftovers of a review card are named and go with it); a branch with commits is parked; the next `start` reuses it with main merged in (a conflict is left in progress for the worker, as `refresh` leaves one); `--to=ready` clears a block that is not a question, and sends a card whose plan does not cover the work on its branch on to planning, where its planner plans the rest. A card a planner holds: `--to=ready` once its planner's plan is applied and current (refused before anything comes down otherwise), `--to=backlog\|dropped` takes the planner off; its clone holds nothing to keep, a fresh planning branch is deleted, a parked one stays parked at its recorded head |
| `stack [ID\|PATH] create\|up\|down\|status\|wait\|reload\|logs\|url` · `stack ID exec -- CMD` · `stack list` · `stack gc [--force]` | Per-card stack. `wait` exits 75 after 110 s, 7 with the log tail when the service's container exited or restarted, 5 while a merge of main is in progress (it only starts the stopped container as it was), and otherwise recreates a stack whose docker files, compose file or lockfiles (at any depth) changed since it came up, as `gates`, `report` and a clean `refresh` do; `reload` recreates it. `gc`: this repository's slots whose clone is gone, plus idle isolated-agent worktrees; `--force` also its unregistered `{app}-wt-*` projects |
| `apply [ID\|--all]` | Apply staged reports/verdicts whose agent is gone; retry hook payloads left in `inbox/` |
| `sync` · `publish` · `sweep` · `attach` | Pull/push `kanban`; push `kanban` + `main` once per run; commit journaled UI writes and prune old runtime files (`--reclaim` also removes idle isolated-agent worktrees; SessionStart runs both); check out the board on this machine |

## Agents

| Command | Who | Does |
|---|---|---|
| `plan ID [--plan-file=.tmp/plan.md --status=ready\|blocked --reason= --question-file=PATH --discovered=* --upstream=* --note=]` | planner, own card | The plan checked (see "Plans") against the clone's HEAD and staged with the hash of the card it covers; applied at Stop (SubagentStop for a subagent): the plan and a `planned` entry on the card, which stays in planning, held, until `stop --to=ready`. Refused at apply when the card's criteria or body changed since: the planner revises it. `--status=blocked` with a reason or an Open question; Provisional decisions go with a ready plan |
| `report ID --status=review\|blocked [--tick=N* --summary= --summary-file=- --verified="cmd → result"* --discovered="bug: Title — body"* --upstream="Title — body"* --reason= --note= --question-file=PATH]` | worker, own card | `review` runs the gates first and refuses on a failure or a conflict marker; staged; applied at SubagentStop (Stop for a `kanban run` agent). `--question-file`: Provisional decisions (review) or an Open question (blocked; the block becomes `question: …`), each in plain words with an `Example:` line, checked when staged, appended to the body once. A worker may report again on its card in review |
| `verdict ID approve\|reject --check=N:pass\|fail:"evidence"* [--issue=* --discovered=* --upstream=* --note=]` | evaluator, own card | Every criterion needs one check; approve needs all pass and no issues; discovered items never decide it |
| `gates` · `migrations [--base=]` · `data-ids [--base=]` · `stack status\|wait\|logs\|up\|reload` | planner, worker, evaluator | Own card only; never `down` |

## Plans

`plan` refuses, naming what to fix, a plan without `## Files`, `## Steps` (numbered) or `## Criteria`, or over 20 000
characters. A Files line is ``- create|change|delete|read `path` — why``: one path from the repository root, outside
`.git` and `.claude/worktrees`, looked up in git (`change`, `delete` and `read` in the commit the plan is made on,
`create` not). Criteria holds one `- N: …` line per criterion of the card, each naming its proof in a code span.

The plan pins contracts and names files to copy from; the worker writes the code. `plan` stages a plan that runs long or
writes the code with a `hint:` line for each: over 8 000 characters or 20 steps, a code block over 12 lines, more than
40 lines of code blocks in all, an inline code span over 200 characters.

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
| review → doing | reject verdict, refresh conflict, or owner "send back" with a note |
| review → done | `finish` only |
| doing/review → ready/backlog/dropped | `stop` only |
| doing/review → planning | `stop --to=ready` of a card whose plan does not cover the work on its branch |
| any → dropped | reason required; `fold` drops with "folded into ID" |

Pull order: capacity = min(max_parallel − doing and planning held on this host, Σ boards (wip.doing − doing)), 0 when
review ≥ wip.review; candidates ready, with a current plan, unblocked, unclaimed, deps satisfied, no shared `area:*`
label with doing/review; sorted parked work first (`work.parked_branch`: work in flight), then priority, epic order,
board order, oldest in ready, id; `urgent` may exceed capacity by 1, one urgent card at work at a time, a planner's
included. A card in review holds its area until `finish`.
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
- Applied under the lock, once per content hash: review/blocked, ticks, `work.head`, agent unbound.
- Evaluator: approve (verdict head = branch head) → `work.approved`; reject → doing, failed criteria unticked. A
  verdict older than the card's last round is dropped (`verdict_superseded`); one on a card that left review files its
  discovered items only.
- Either agent's `--discovered` items become cards in the backlog of the card's board, label `discovered`, unless a
  card with the same title is open or the card filed it before. `--upstream` findings become log entries.
- A refusal is kept for the agent: `context` shows it, the failing gate first.

## Recovery

| Failure | Do |
|---|---|
| Hook error | The PreToolUse hook never blocks and ignores its own errors. SubagentStop writes `inbox/` first: `apply --all` retries. `doctor` checks paths |
| UI and CLI write at once | Same lock; stale rev → 409 in the UI; every write is one commit |
| Teammate without the merge driver | `attach` / `doctor --fix` configure it; SessionStart verifies |
| Container git unusable (UI) | Writes go to the journal; the next host write, `sweep` or `sync` commits them |
| No stack slot / resources | Exit 7 names it: `stack gc`, finish or stop a card, or widen the Docker address pools |
| Main behind merged migrations | `finish` runs `kanban.migrate` and `finish.after`, and rebuilds main's stack when lockfiles, docker files or the compose file changed: `build` while it serves, then `up --force-recreate --wait` (`--no-rebuild` skips it). Under `kanban run`, a failed rebuild is an attention line |
| Board version 1 | Every board command refuses; the owner runs `/implement-kanban` (`fold-boards`). `sync`, `doctor`, `attach` and `kanban:install` still run |
| A card deleted on origin, edited here | Sync keeps the local copy in `.git/laravel-house/displaced/`; `doctor` lists it |

## Driving the board by hand

Only where `kanban run` cannot stay alive (a cloud session, no `setsid`): the main session drives the board itself, with
background subagents.

### Keeping agents busy

- Finish approved cards first: cards in review hold stacks and areas.
- Planning and ready hold work for every free slot: `promote --auto` fills planning, and the slots no ready card can
  take go to planners (`next --planning`).
- Every question for the owner goes out early and in one batch, never one at a time as agents hit them.
- `next` returns none while slots are free: `next -v` says why each ready card waits. With nothing to start or plan,
  spawn read-only audit agents (no isolation, no card), one per area, that check the code against its `CLAUDE.md` rules
  and return findings to card.
- "Stop": ask once whether the owner means **drain** (start nothing new; keep evaluating, finishing and resending
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
7. **Worker finished, card in review** (`wait <ID>` printed review).
   `refresh <ID>` merges main into the branch:
   - `up to date` / `refreshed` prints the evaluator's spawn line: spawn it.
   - exit 3 `its worker is still running`: `wait <ID>`, then again.
   - exit 5 conflict: the card is back in doing; send the printed `SendMessage:` text to the worker, or spawn a fresh
     one with the line `show <ID>` prints.
8. **Worker blocked.** `show <ID>` has the reason. A question: `stop <ID> --to=backlog`, with the question on the card
   (see below), and into the owner batch. Anything else: fix the cause, or leave it blocked for the owner.
9. **Approved.** `finish <ID>` merges, tears the card's stack down and removes its clone, then installs changed
   dependencies on main, runs the migrate and after-steps and the main check, rebuilds main's stack when its image
   inputs changed, and pushes main every `publish.every` merges. That can take minutes: run it in the background
   (`run_in_background`) and act on its exit when it ends.
   - exit 5 `main moved`: `refresh <ID>`, then a fresh evaluator. exit 5 `does not merge cleanly`: the card is in
     doing; `refresh <ID>` hands the conflict to the worker.
   - exit 3: the message names it (uncommitted main files, a live agent, an approval head mismatch, leftover conflict
     markers: the card is back in doing; send the worker the listed lines). A card that changes kanban's own files
     (`.claude/`, `config/kanban.php`, hooks, `.gitattributes`): show the owner the diff; `--force` with their OK.
   - `main is red`: the main check failed after an earlier merge, and the bug card it filed holds the next `finish`:
     start that card next (`--force` merges anyway, with the owner).
   - exit 6: another session holds the lease and nothing merged (see Ground rules).
   - `main: not pushed (…)`: `publish` later. `warning: rebuild main failed`: read the error, then run the printed compose command.
   - Finish approved cards in the order the brief lists them, oldest approval first.
10. **Rejected.** The card is back in doing; `show <ID>` prints the `SendMessage` text for its worker, or spawn a
    fresh worker with the line `show <ID>` prints.
11. **Repeat** 2–10 while there is capacity and work to plan or start.
12. **Publish** at the end, and after a `not pushed` line: `publish` pushes `kanban` and `main`. Never force-push.
13. **One message to the owner:** done (ids, titles, merge shas), in flight, the questions in one batch, refused
    promotions, what you decided on your own, and pending package findings. Facts only.
