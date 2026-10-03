---
name: kanban
description: >-
  For a project on the kanban board (docs/kanban present, kanban:install run).
  Orchestrate the board from the main session: plan cohesive cards an agent
  finishes in one go, keep every slot busy, start each card in its own clone
  and Docker stack, spawn background kanban-worker and kanban-evaluator
  agents, react to their reports and verdicts, merge approved work with
  `finish`, and report back to the owner. Covers card sizing and folding,
  open questions on cards, the run loop step by step, the exact
  vendor/bin/kanban commands and exit codes, and filing package findings
  upstream. Use when asked to run, work through or drive the board, plan or
  split work into cards, start or continue cards, merge finished work, or
  record the owner's answer. Triggers — kanban, board, run the board, next
  card, ready cards, plan cards, fold cards, start card, kanban-worker,
  kanban-evaluator, finish card, publish, vendor/bin/kanban, docs/kanban.
---

# Kanban orchestrator (main session)

No `docs/kanban` in the project: if the root `CLAUDE.md` has the `laravel-house:kanban:start` block, this clone is
not attached yet: run `vendor/bin/kanban attach`, then `doctor`. Without the block, the board is not adopted: offer
the owner `/implement-kanban`, and stop. `an older board format: the owner runs /implement-kanban` means the same.

You plan the cards and drive the board; agents do the card work. Exact flags, every exit code and the rarer failures
are in `references/protocol.md`; card sizing in depth is `references/planning.md`. All commands below are
`vendor/bin/kanban <command>` run from the main checkout.

## Ground rules

- Only the main session runs `start`, `refresh`, `finish`, `stop`, `publish`, `promote`, `new`, `set`, `move`, `fold`
  and `upstream file|dismiss`, from the main checkout. One main session per machine holds the lease; another one gets
  exit 6. `lease --takeover` when the holder is your own previous session, otherwise only when the owner says so.
- Never edit `docs/kanban` by hand and never write code in the main checkout for a card.
- Cards are for agents, not people: one card is one cohesive piece of work on one `area:*`. Every agent spawn costs a
  bootstrap, a clone, a stack and a review; a card that is too small wastes all four.
- Cards in doing, review and done are locked: `set` takes `note=`, `blocked=`, `tick=`, `untick=`, and
  `accept[N]="…" --reason="…"` (a reworded criterion, logged; the stack and the agent stay). `stop` it for anything
  else; `--force` only when the owner asks.
- The owner answers questions and sets rules; you record them (see "Questions and rules"). Never pick an answer.
- What the owner must hear goes on the board as you go (`set <ID> note="…"`, question blocks), never only in a
  scratchpad or under `/tmp`.
- The agents' model and effort come from `kanban.agents` in `config/kanban.php`: change them there, `doctor --fix`,
  restart Claude Code.

## Planning cards

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
- **Group criteria per surface**: up to 24, each one the evaluator can check.
- **Check each criterion against the code** (grep every route, file, config key or generated artifact it names, or
  state that the card creates it), against the governing `CLAUDE.md` rules (test technique included), and against the
  open questions on the same topic. Name the real entry point that shows it, never "the full suite passes".
- **A page change** names the browser spec that proves it, and the design reference section when the owner decided one.
- **A shared contract** (a type, rule, enum or event several cards use) is its own small card on its own area.
- **Discovered items** fold into the open card on their area before a new card is made; check each against main first.
- After a rename lands, grep the open cards' criteria for the old names before their workers start.

## Keeping agents busy

- Finish approved cards first: cards in review hold stacks and areas.
- Ready holds at least the free capacity in startable cards on distinct areas (`promote --auto` counts only those).
- Every question for the owner goes out early and in one batch, never one at a time as agents hit them.
- `next` returns none while slots are free: `next -v` says why each ready card waits. With nothing startable, spawn
  read-only audit agents (no isolation, no card), one per area, that check the code against its `CLAUDE.md` rules and
  return findings to card.
- "Stop": ask once whether the owner means **drain** (start nothing new; keep evaluating, finishing and resending
  rejects; then publish and report) or **stop all** (no spawns at all), unless the words already say.

## The run loop

1. **Orient.** `status`: WIP, cards in flight with their agents, blocked cards, questions, next, checks.
   - A `checks:` item not ok → `doctor` (`doctor --fix` for wiring) before starting work.
   - Cards in doing with `no agent`, or a `stopped`/`stale` agent → "Recover" first.
2. **Fill Ready.** `promote --auto`: backlog cards whose dependencies are done, up to `ready_buffer` startable cards.
   Refusals (no criteria, no area, an open question) go into the summary.
3. **Pick.** `next --count=<free capacity>`; `none: …` → step 5.
4. **Start each.** `start <ID>` prints the clone, branch, stack and, last, the spawn line:
   `Agent(subagent_type="kanban-worker", description="…", prompt="Card <ID>. Worktree <path>")`.
   Spawn exactly that, in the background, unchanged, without `name` or `isolation`, and one kanban spawn per message:
   the agent binds to its card from that spawn when it starts. Context
   for the agent goes on the card (`set <ID> note=`), never into the prompt.
   - exit 3 refused: skip the card. exit 8 claim lost: `next` again, without it.
   - exit 9 remote unreachable: nothing was claimed; wait, report it, never set `KANBAN_SYNC=off` yourself.
   - exit 7 or 1 after the claim: the card stays in doing, blocked; fix the cause, then `start <ID>` again resumes it.
5. **Wait.** An agent ends with a hand-back message, then stops, and its stop applies the report or verdict. On a
   hand-back, run `wait <ID>`: it returns once that is done, with the card's line (`stop refused`: the agent works on;
   its next hand-back comes later). Then act on the card's state. A task notification may come late or start no turn:
   never wait for one. While agents run and nothing else is to do, keep one `wait --timeout=1800` running in the
   background (`run_in_background`): it ends when any agent settles, so a lost hand-back costs nothing. After acting,
   start it again. Never poll in a loop of your own.
6. **Worker finished, card in review** (`wait <ID>` printed review).
   `refresh <ID>` merges main into the branch:
   - `up to date` / `refreshed` prints the evaluator's spawn line: spawn it.
   - exit 3 `its worker is still running`: `wait <ID>`, then again.
   - exit 5 conflict: the card is back in doing; send the printed `SendMessage:` text to the worker, or spawn a fresh
     one with the line `show <ID>` prints.
7. **Worker blocked.** `show <ID>` has the reason. A question: `stop <ID> --to=backlog`, with the question on the card
   (see below), and into the owner batch. Anything else: fix the cause, or leave it blocked for the owner.
8. **Approved.** `finish <ID>` merges, tears the card's stack down and removes its clone, then installs changed
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
   - `main: not pushed (…)`: `publish` later. `warning: rebuild main failed`: read the error, then run the printed compose command.
   - Finish approved cards in the order the brief lists them, oldest approval first.
9. **Rejected.** The card is back in doing; `show <ID>` prints the `SendMessage` text for its worker, or spawn a
   fresh worker with the line `show <ID>` prints.
10. **Repeat** 2–9 while there is capacity and ready work.
11. **Publish** at the end, and after a `not pushed` line: `publish` pushes `kanban` and `main`. Never force-push.
12. **One message to the owner:** done (ids, titles, merge shas), in flight, the questions in one batch, refused
    promotions, discovered items to triage, and pending package findings. Facts only.

## Questions and rules

- **An open question** rides on the work card it blocks: `set <ID> blocked="question: <the question>"` and an
  `## Open question` section in its body (options, trade-offs). It stays in backlog until answered.
- Before asking, check the `CLAUDE.md` rules and `docs/kanban/decisions.md`: a question they settle is not asked;
  write the answer onto the card and cite the rule.
- **The owner answers:** add `## Owner answer (YYYY-MM-DD)` to the body, rewrite the criteria it changes, clear the
  block (`set <ID> blocked=`), then `promote <ID>`. Rewrite the other open cards it changes too.
- **A standing rule** the owner states goes into the `CLAUDE.md` of the directory it governs, as a criterion on the
  first card that needs it, or straight away when the owner asks.
- `docs/kanban/decisions.md` is the read-only archive of decisions recorded before the board had questions.

## New work

`new work "<title>" --type=feature|bug|chore|spike --label=area:<area> [--epic=<slug>] --priority=… --body-file=- --accept="…" …`,
then `promote <ID>` when the owner wants it now. Read the `hint:` lines it prints.

## Recover

- **Agent stopped or stale, card in doing:** `show <ID>` and `context <ID>`. A staged report never applied → `apply <ID>`.
  Otherwise SendMessage the old worker, or spawn a fresh one with the line `show <ID>` prints.
- **Stack down or unhealthy:** `stack <ID> wait`; `stack <ID> logs` for failures; `stack <ID> exec -- <cmd>` to look.
- **Give up on a card:** `stop <ID> --to=ready|backlog|dropped [--reason=…]` (a branch with commits is parked and
  reused by the next `start`).
- **Leftovers:** `stack gc`, `doctor`, `sweep`, `apply --all`.

## Package findings

Workers and evaluators log a defect in the house package itself with `--upstream`; `upstream` lists them. With
`KANBAN_UPSTREAM` on: `upstream file <ID>:<logid>` searches open issues and files it on the package's repository
(`--comment=N` to add to a match, `--new` to file anyway), refusing text that names this project; or
`upstream dismiss <ID>:<logid> --reason=…`. Off: list them in the owner summary.

`references/gotchas.md` holds the board's known surprises; read it when something behaves unexpectedly. It ships
with the package: never edit it. A surprise specific to this project goes into the project's own `CLAUDE.md`.
