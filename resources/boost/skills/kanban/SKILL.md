---
name: kanban
description: >-
  For a project on the kanban board (docs/kanban present, kanban:install run).
  The main session orchestrates: it cuts cohesive cards an agent finishes in
  one go, runs `vendor/bin/kanban run --until-attention` in the background
  (the routine in code: clones, Docker stacks, headless agents, the merge
  queue) and acts on what it hands back, and runs the owner's morning: the
  summary, every open question
  as a multiple choice, the answers recorded with `kanban answer`. Covers card
  sizing and folding, the question format, provisional decisions, wrapping
  up, recovery, the exact vendor/bin/kanban commands and exit codes, and
  filing package findings upstream. Use when asked to run or drive the board,
  cut or split work into cards, answer or record the owner's questions, do
  the morning, or wrap up. Triggers — kanban, board,
  run the board, kanban run, morning, questions, answer, cut cards, fold
  cards, kanban-planner, kanban-worker, kanban-evaluator, kanban-merger,
  merge queue, vendor/bin/kanban.
---

# Kanban orchestrator (main session)

No `docs/kanban` in the project: if the root `CLAUDE.md` has the `laravel-house:kanban:start` block, this clone is
not attached yet: run `vendor/bin/kanban attach`, then `doctor`. Without the block, the board is not adopted: offer
the owner `/implement-kanban`, and stop. `an older board format: the owner runs /implement-kanban` means the same.

You orchestrate the board and run the owner's morning; `kanban run` does the routine steps, and agents do the card work. Exact flags, every exit code and the rarer failures
are in `references/protocol.md`; card sizing in depth is `references/planning.md`. All commands below are
`vendor/bin/kanban <command>` run from the main checkout.

## Ground rules

- Only the main session (and the `kanban run` it starts) runs `start`, `refresh`, `finish`, `stop`, `publish`, `promote`, `new`, `set`,
  `move`, `fold`, `plan`, `answer`, `drain` and `upstream file|new|dismiss`, from the main checkout. One orchestrator per machine holds
  the lease; another one gets exit 6 from `start`, `refresh`, `finish`, `stop` and `apply`, and nothing changed. While
  `kanban run` is running, leave starting, refreshing, merging and stopping cards to it. A session that ends frees its lease;
  a new session of the same transcript (after `/compact` or a restart) takes it at SessionStart. `lease --takeover`
  when the holder is your own previous session, otherwise only when the owner says so.
- Never edit `docs/kanban` by hand and never write code in the main checkout for a card.
- Cards are for agents, not people: one card is one cohesive piece of work on one `area:*`. Every card costs a plan, a
  worker, a review and their clones and stacks; a card that is too small wastes them all.
- Every card is planned before a worker starts it: an Opus planner writes the plan in a `planning` stage between backlog
  and ready, and the Sonnet worker follows it. A ready card whose criteria or body change goes back to planning.
- Cards in doing, review and done are locked: `set` takes `note=`, `blocked=`, `tick=`, `untick=`, and
  `accept[N]="…" --reason="…"` (a reworded criterion, logged; the stack and the agent stay). `stop` it for anything
  else; `--force` only when the owner asks.
- Act; do not ask (the root `CLAUDE.md`'s Kanban section). The owner answers product questions and sets rules; you
  record them ("Questions and rules"). A technical question, a card that makes the app safer or cleaner, a discovered
  improvement: you decide, and the summary says what you decided.
- What the owner must hear goes on the board as you go (`set <ID> note="…"`, question blocks), never only in a
  scratchpad or under `/tmp`. A note is yours, never the owner's: evaluators take owner authority only from an
  `## Owner answer` section or a `CLAUDE.md` rule.
- The agents' model and effort come from `kanban.agents` in `config/kanban.php`. `kanban run` passes them as flags
  to each agent it starts; `doctor --fix` writes them into the agent files for agents you spawn yourself.

## Cutting cards

Before a card is created, and before it is promoted:

- **One area, one open card.** Every card carries an `area:*` label (`promote` refuses one without). Cards that share
  an area never run at once, so related work on one surface is one card. Areas are surface or directory sized, and
  there are enough of them to fill `max_parallel`.
- **One board; epics for goals.** Cards live on the `work` board. A finite goal the owner names (a feature, a
  migration) is an epic: `epic <slug> "Title" --goal=… --done-when=…`, and each of its cards gets `--epic=<slug>`. An
  area is where work happens and decides what runs at once; an epic is why, and finishes. Never one per area or phase.
- **No enabler cards.** A card whose only job is to unblock others is folded into them; `hubs:` in the brief and the
  `hint:` lines of `new` and `set` point at candidates. `fold <FROM>… --into=<ID>` merges them in one commit.
- **Split only to run in parallel**, on different areas, and only when each part is worth an agent of its own.
- **The body is for people, the plan for agents.** The body says what and why in short, plain sentences: the outcome
  the owner sees. Files, steps, traps and commands go in the plan
  (`references/planning.md`, "The body and the criteria").
- **One observable outcome per criterion**, up to 24. Each names where it shows and the test that proves it. Never an
  implementation step or "the full suite passes" (the same section). Check them against the governing `CLAUDE.md`
  rules (test technique included) and the open questions on the same topic. The planner checks them against the code,
  and blocks a card whose criterion cannot be done as written.
- **A page change** names the browser spec that proves it, and the design reference section when the owner decided one.
- **A shared contract** (a type, rule, enum or event several cards use) is its own small card on its own area.
- **Discovered items** fold into the open card on their area before a new card is made; check each against main first.
- After a rename lands, grep the open cards' criteria for the old names before their workers start.
- **Rule files stay lean.** The brief's `rules over 40 KB` line names a `CLAUDE.md` every agent there reads whole: plan
  a chore card on its area that prunes it (stale, redundant or one-off text out, detail into a doc it points to).
- **A rule the evaluator rejects for twice** that grep can check (a forbidden call, a fixed sleep) becomes a
  `gates.report` entry in `config/kanban.php`, so `report` refuses it before an evaluator spawns (from the card's
  merge on: `gates` in references/protocol.md).

## Running the board

You orchestrate; `kanban run` does the routine in code: evaluates review cards as they are, merges approved cards
through the merge queue (one merge at a time for the whole project; a `kanban-merger` agent for a conflict or a failed
check), resumes rejected or sent-back workers (merging main first), parks question cards in backlog, moves planned cards
to ready, promotes cards into planning, starts ready cards up to capacity and planners on the slots workers leave, each
agent a headless `claude -p` session. It runs under your session's lease. The queue merges nothing until main's
`finish.check` names the whole suite: set it with the owner (house README, "The Merge Queue").

1. `status`. A `checks:` item not ok → `doctor` first.
2. Start `vendor/bin/kanban run --until-attention` in the background (`run_in_background`). It returns with an
   `attention:` block when something needs you, or after 25 min with `nothing needs you: run it again`.
3. Act on each `attention:` line, then start it again:
   - `<ID> blocked: …` (its agent, the stop gate, three runs without progress, or a failed command, `kanban run: …`):
     `show <ID>`, `context <ID>` and the run's log in `.git/laravel-house/runs/`. Fix the cause, then `set <ID>
     blocked=` (its agent resumes, a worker with main merged in), or `stop` it. A cause on main is fixed on main:
     commit, `publish`, then `set <ID> blocked=`. A planner blocks on a criterion that cannot be done as written, or a
     card too big for one worker: reword the criterion (`set`) or split the card, then unblock it.
   - `<ID> parked in backlog: question: …` or `<ID> waits on the owner: question: …`: it waits for the owner's batch
     ("Morning").
   - ``main is red: `<command>` fails on main … at <sha7>``: fix it on main (run the command at that commit, fix,
     commit, `vendor/bin/kanban publish`). When the queue found it, every approved card waits (`waits for main to be
     fixed (…)`) until origin's main moves; the card that found it keeps its approval. Never cut a card for it.
   - ``a check timed out: `<command>` timed out after N s at <sha7>, merging <ID>``: the merge ended and nothing ran it
     again; every approved card waits (`waits for a timed-out check (…)`) until main moves or `<ID>` is blocked. A slow
     suite: with the owner, give its entry more time (`['run' => …, 'timeout' => …]` in `config/kanban.php`) or make it
     faster, commit on main, `publish`. `<ID>`'s own tests hang (`show <ID> --log=5` has the output): `set <ID>
     blocked="…"` lifts the hold; then `move <ID> doing --reason="…"` and `set <ID> blocked=` hand it to its worker.
   - `<ID> merged, then: …`: the card is done; following main on this machine (an install, the rebuild, `migrate`,
     `finish.after`) failed. Fix it on main. A line about its stack, clone or branch here (`stack down failed`,
     `branch … kept`, `has uncommitted changes`): as for `done; its leftovers here` below.
     `… reached main but left review`: its code is on main, but the card was moved during the push; tell the owner.
   - `<ID> done; its leftovers here: …`: the card's clone, stack or branch on this machine could not go. `has uncommitted
     changes`: tell the owner, who decides what of them to keep; once the clone is clean or gone, the next run tidies it.
   - `main checkout …` (`not moved`, `not brought to the remote's main`, or `main checkout: <step>: …`): this machine's
     main checkout could not follow origin's main, or a step after it failed; merging goes on. The line names the fix:
     commits only here → `publish` them (diverged from origin's main: the owner merges it by hand first);
     uncommitted changes → commit or stash them with the owner; a failed step → fix it on main.
   - `<ID> merge failed: …`, or another line that starts with `<ID>:`: the queue retries in 5 min. `finish.check: …
     not found in the app container`: the command is written for the host; make it the plain command as the container
     runs it.
   - `<ID> sent back by the merge: …`, `merge lease held by … no beat for N min`: for your summary; the board carries
     on (a lease whose beat stood still 15 min is taken over).
   - `promote --auto: skipped <ID>: …`: that card could not be written (an invalid card file); `validate` names the
     fault. Fix it; the other cards were promoted.
   - `upstream: N … pending`: "Package findings".
   - `paused until …: usage limit`: nothing to do; start it again.
   - `idle: …`: cut or promote cards ("Cutting cards"); with nothing to cut, report to the owner and stop.
   - `kanban run failed: …`: read it; a defect in the package is `upstream new`; start it again.
4. **Wrap up** ("stop", "drain"): `vendor/bin/kanban drain` (never kill a run: it may be mid-merge), then keep
   starting `run --until-attention` as above until it prints `drained`, then `publish` and one message to the owner. Agents already running finish on their own; a new `run` picks them up.
- Keep the pipeline full: `promote --auto` fills planning, counting the ready cards waiting to start and the planning
  cards a planner holds or may take, one card ahead per area; `next -v` says why ready cards wait, `next --planning -v`
  why planning cards do.
- Where a background command cannot run (a cloud session that ends turns), drive the board by hand:
  `references/protocol.md`, "Driving the board by hand".

## Morning

While `kanban run` is not running (between hand-backs), so no answered card starts before its criteria are rewritten:

1. `morning`: the board, what merged since, cards blocked without a question, questions, discovered cards waiting for
   criteria, agent runs and tokens.
2. `questions`, then ask the owner through the multiple-choice prompt (AskUserQuestion), up to four per call. The
   owner builds with AI and may not know the code, so each question says in plain words what is being decided, why
   it matters and its example, with any term explained (never a class, file or field name unexplained). Each option
   is a choice whose description says what changes for the owner's users; the recommended option first and marked
   "(Recommended)". A free-form question goes as it is, rewritten the same way.
3. `answer <ID>#<n> <option> [--note=…]` for each. An answer that changes a card's criteria: rewrite them (`set`). A
   provisional decision answered differently: the follow-up card that changes it. A standing rule: "Questions and
   rules".
4. Cards blocked without a question: as in step 3 of "Running the board". Pending package findings: "Package findings".
5. Plan new cards from the owner's notes; give each discovered card criteria, or fold it into the open card on its
   area. `promote --auto`, start `kanban run --until-attention` again.
6. One message: what merged, what you decided, what still waits on the owner. Facts only.

## Questions and rules

- **One format**, in the card's body; 2–4 options, so each fits the multiple-choice prompt:
  ```markdown
  ## Open question
  What is decided and why it matters, in plain words: what the user sees, what goes wrong if it is decided badly.
  Example: one concrete case the owner can picture (a screen, a value, what a user does and sees).
  1. Option — what it means for users
  2. Option — what it means for users
  Recommended: 1 — why
  ```
- **An open question** blocks its card in backlog until answered: real data, production, accounts, money, publishing,
  legal, loosening security, or a product question that is hard to change later. Planners and workers report it with
  `--question-file`; when you cut a card with one, write the section into the body and `set <ID> blocked="question: <…>"`.
- **A provisional decision** (`## Provisional decision`, the same form plus `Taken: N`) is a product choice that is
  easy to change later, or one whose answer changes none of the card's criteria: the agent took the recommended
  option and went on; the owner confirms it in the morning. An answer with another option sends a planned card back
  to planning; on a card at work, a follow-up card makes the change.
- Any other question you answer yourself: `## Decision (YYYY-MM-DD)` in the card's body, with the reason.
- Before asking, check the `CLAUDE.md` rules and `docs/kanban/decisions.md`: a question they settle is not asked;
  `answer <ID>#<n> <option> --note="<the rule>"` records it. Only `answer` closes a question; a section written by
  hand leaves it open.
- **The owner answers:** `answer` records it, clears the block and promotes the card; rewrite the criteria it changes,
  and the other open cards it changes too.
- **A standing rule** the owner states goes into the `CLAUDE.md` of the directory it governs, as a criterion on the
  first card that needs it, or straight away when the owner asks. With a `config/house.php`, never into a house rule
  (between `house:begin` and `house:end`, an `.ai/` file setup's templates ship, the root `CLAUDE.md`'s Boost block):
  those are rendered, so a rule that changes one is an override (the root `CLAUDE.md`, Where the docs live): the file
  under `overrides` in `config/house.php`, or a root topic in `.ai/guidelines/petar-spasic/laravel-house/`.
- `docs/kanban/decisions.md` is the read-only archive of decisions recorded before the board had questions.

## New work

`new work "<title>" --type=feature|bug|chore|spike --label=area:<area> [--epic=<slug>] --priority=… --body-file=- --accept="…" …`,
then `promote <ID>` (into planning) unless it waits on a product question. Read the `hint:` lines it prints. A plan the
owner hands you for a card in planning: `plan <ID> --plan-file=…`, in the planner's format (`references/protocol.md`).

## Recover

- **Stack down or unhealthy:** `stack <ID> wait`; `stack <ID> logs` for failures; `stack <ID> exec -- <cmd>` to look.
- **Give up on a card:** `stop <ID> --to=ready|backlog|dropped [--reason=…]` (its `kanban run` agent is ended; a
  branch with commits is parked; it is planned again, and the next `start` reuses it with main merged in, printing a
  conflict for the worker to conclude first; a drain never restarts it). A card a planner holds: `stop <ID> --to=backlog|dropped`.
- **A merge that stays stuck** (`status`'s `merge:` line): `stack _merge logs`; once no `finish` runs, `finish <ID>
  --abort` gives the lease back and leaves the card queued (exit 11 `a merge runs here` while one does: each check ends
  within its timeout, 30 min by default, or `stop <ID> --to=…` ends one `kanban run` started; refused while main is pushed: `finish <ID>` settles that push first).
- **Leftovers:** `stack gc`, `doctor`, `sweep`, `apply --all`.

## Package findings

Workers and evaluators log a defect in the house package itself with `--upstream`; `upstream` lists them. With
`KANBAN_UPSTREAM` on: `upstream file <ID>:<logid>` searches open issues and files it on the package's repository
(`--comment=N` to add to a match, `--new` to file anyway), refusing text that names this project; or
`upstream dismiss <ID>:<logid> --reason=…`. A defect you meet yourself: `upstream new "Title — body"`, same search
and checks. Off: list them in the owner summary.

`references/gotchas.md` holds the board's known surprises; read it when something behaves unexpectedly. It ships
with the package: never edit it. A surprise specific to this project goes into the project's own `CLAUDE.md`.
