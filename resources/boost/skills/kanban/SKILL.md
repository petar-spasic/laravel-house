---
name: kanban
description: >-
  For a project on the kanban board (docs/kanban present, kanban:install run).
  Orchestrate the board from the main session: pull Ready cards,
  start each in its own worktree and Docker stack, spawn background
  kanban-worker and kanban-evaluator agents, react to their reports and
  verdicts, merge approved work with `finish`, push once with `publish`, and
  report back to the owner. Covers the run loop step by step, the exact
  vendor/bin/kanban commands, the exit codes to react to, and recording the
  owner's decisions as cards. Use when asked to run, work through or drive the
  board, start or continue cards, merge finished work, or record a decision.
  Triggers — kanban, board, run the board, next card, ready cards, start card,
  kanban-worker, kanban-evaluator, finish card, publish, decision card,
  vendor/bin/kanban, docs/kanban.
---

# Kanban orchestrator (main session)

No `docs/kanban` in the project: if the root `CLAUDE.md` has the `laravel-house:kanban:start` block, this clone is
not attached yet: run `vendor/bin/kanban attach`, then `doctor`. Without the block, the board is not adopted: offer
the owner `/implement-kanban`, and stop.

You drive the board; agents do the card work. Exact flags, every exit code and the rarer failures are in
`references/protocol.md`. All commands below are `vendor/bin/kanban <command>` run from the main checkout.

## Ground rules

- Only the main session runs `start`, `refresh`, `finish`, `stop`, `publish`, `promote`, `new`, `set`, `move`, from the main checkout (they refuse inside a card worktree).
  One main session per machine holds the lease; another one gets exit 6. `lease --takeover` when the holder is your
  own previous session (after a restart), otherwise only when the owner says the other session is dead.
- Never edit `docs/kanban` by hand and never write code in the main checkout for a card: every card is a worktree.
- Cards in `doing`, `review`, `done` and `superseded` are locked (`locked` in kanban.json): `set` takes only `note=`, `blocked=`,
  `tick=`, `untick=` on them and exits 3 otherwise. Put a `doing` or `review` card back with `stop` to edit it; `set … --force`
  overrides (the only way for `done` and `superseded`), and only when the owner asks: an agent and the evaluator work from the
  card as they read it.
- Decisions are the owner's. You record them, you do not make them (see "Decisions").
- Parallel work is bounded by `next`: never force past it without the owner.
- The agents' model and effort come from `kanban.agents` in `config/kanban.php`: change them there, run `doctor --fix`
  and restart Claude Code.

## The run loop

1. **Orient.** `status` — WIP, cards in flight with their agents, blocked cards, next, checks.
   - Any `checks:` item not ok → `doctor`; fix what it names (`doctor --fix` for wiring) before starting work.
   - Cards in doing with `no agent` or a `stopped`/`stale` agent → recovery ("Recover" below) before new work.
2. **Fill Ready.** `promote --auto` moves backlog cards that pass the ready policy into ready, up to `ready_buffer`.
   A `refused KEY-…: R4 no acceptance criteria` line is a card the owner must complete: collect it for the summary.
3. **Pick.** `next --count=<free capacity>`. `none: review 6/6 (stop starting)` or `none: …` → go to step 5.
4. **Start each.** `start <ID>`:
   - exit 0 prints the worktree, branch, stack URL and ports, and the spawn line as its last line:
     `Agent(subagent_type="kanban-worker", description="KEY-XXXXXX <title words>", isolation="worktree", prompt="Card KEY-XXXXXX. Worktree /…/.claude/worktrees/xxxxxx-<slug>")`.
     Spawn exactly that, in the background, unchanged (no `name`: a named spawn can become a teammate without isolation).
     The PreToolUse hook records the spawn and the WorktreeCreate hook hands the card's worktree to it, oldest spawn first,
     within 2 minutes: never spawn another isolated agent (a fork with `isolation`) in the same message.
   - exit 3 refused (policy, capacity, not on main, merge in progress, no stack slot): read the message; skip the
     card. Cards in review hold stacks too: finish approved ones first.
   - exit 8 claim lost (another machine took it): `next` again, and do not pick that card again in this run.
   - exit 9 remote unreachable, or the push lost the race three times: nothing was claimed. Wait and retry; do not loop on it. Never add `KANBAN_SYNC=off` yourself: report the exit 9 to the owner.
   - exit 7 or 1 after the claim: the card stays in doing with `blocked` set; `show <ID>`, fix the cause
     (`stack <ID> logs`, `doctor`), then `stop <ID> --to=ready` and start again.
   - Repeat 3–4 while `next` returns cards.
5. **Wait.** Background agents notify you when they finish with one line: `<ID> review|blocked: …` (worker) or
   `<ID> approve|reject: …` (evaluator). The SubagentStop hook has already applied their report or verdict to the
   board; read state with `show <ID>`, never from the message alone. Do not poll in a loop; start more work
   (step 3) whenever a slot frees.
6. **Worker finished, card in review.** A spike's findings are in its report summary (`show <ID>`): write them onto
   its decision card (`set <DEC> body=@-`) first, since workers cannot write cards. A criterion only a container or a
   browser can prove (an image build, a process inside the app container, behaviour in a page): agents cannot run
   `docker`, so check it yourself and record the evidence with `set <ID> note="…"` (`tick=N` when the worker could
   not); `context` shows notes to the worker and the evaluator. A defect you can already show: `move <ID> doing
   --reason="…"`, `set <ID> untick=N`, and SendMessage the worker what to fix. Then `refresh <ID>` merges the
   current main into the branch:
   - `up to date` / `refreshed …` → spawn the evaluator in the background:
     `Agent(subagent_type="kanban-evaluator", description="<ID> review <title words>", isolation="worktree", prompt="Card <ID>. Worktree <path>")`.
   - exit 5 conflict: the card is back in doing and `refresh` prints `SendMessage: …`. Send that text to the
     worker (the agent id the spawn returned; `status` shows its first 4 characters); if the worker is gone, spawn a fresh kanban-worker with the start line.
7. **Worker finished, card blocked.** `show <ID>` has the reason. Questions for the owner go into the summary;
   a missing decision becomes a proposed card on `project/decisions` (`new … --stage=proposed`) that the work card
   `depends_on`. Leave the card in doing (blocked) or `stop <ID> --to=backlog --reason="…"` if it waits long.
8. **Verdict approve.** `finish <ID>` merges `--no-ff` into main, marks the card done, tears the stack down and
   removes the worktree and branch.
   - exit 5 `main moved … refresh and re-verify` → `refresh <ID>`, then a fresh evaluator (step 6).
   - exit 5 `does not merge cleanly` → the card is in doing; `refresh <ID>` hands the conflict to the worker.
   - exit 3 → the message names it (uncommitted main files, live agent, approval head mismatch). Fix, retry.
   - `rebuild main: … changed` → run the printed `docker compose … up -d --build` for the main stack.
9. **Verdict reject.** The card is back in doing with the failed criteria unticked. SendMessage the worker (the agent
   id its spawn returned): "Evaluator rejected <ID>; run `vendor/bin/kanban context` for the failed checks, fix, report
   again." Worker gone → spawn a fresh kanban-worker with the start line.
10. **Repeat** steps 3–9 while there is capacity and ready work.
11. **Publish once** at the end of the run: `publish` pushes `kanban` and `main`.
    - exit 9 remote failure after retries: report it; never force-push.
    - exit 5 main diverged and the merge conflicts: resolve by hand on main, then `publish` again.
12. **One message to the owner:** done (ids and titles, merge shas), in flight, blocked with the questions,
    decisions needed (proposed cards), refused promotions and what they miss. Facts only.

## Decisions

- The owner decides; you record. A decision the owner states in chat:
  `new project/decisions "<decision as a rule>" --stage=decided --decided-on=YYYY-MM-DD --why="<the owner's reason>" --body="<details>"`
- It replaces an older one: `set <NEW> supersedes=+<OLD>` (the old card becomes superseded automatically).
- An open question you hit: `new project/decisions "<question>" --stage=proposed --body="<options, trade-offs>"`,
  and ask the owner. Never mark it decided yourself.
- Decided cards are binding for every card; a card that contradicts one is sent back.

## New work

- The owner asks for something: `new <epic>/<board> "<title>" --type=feature|bug|chore|spike --priority=… --body-file=- --accept="…" …`
  (1–12 criteria, each one the evaluator can check), then `promote <ID>` if the owner wants it now.
- Spikes: workers cannot write cards or run `docker`. Phrase criteria as "the report contains …; main records it on
  <decision card>", and say in the body which part needs a container: you run that part.
- Workers' and evaluators' `--discovered` items land in the backlog of the card's board with label `discovered`:
  triage them in the summary.

## Recover

- **Agent stopped or stale, card in doing:** `show <ID>` and `context <ID>` (commits, dirty files). A staged report
  that was never applied → `apply <ID>`. Otherwise SendMessage the old worker if it can resume, or spawn a fresh
  kanban-worker with the start line (it re-binds on EnterWorktree and continues from the branch).
- **Stack down or unhealthy:** `stack wait` (worker) or `stack <ID> wait` (main) brings a stopped stack back up; `stack <ID> logs` for failures.
- **Give up on a card:** `stop <ID> --to=ready|backlog|dropped [--reason=…]` (branch with commits is parked and reused
  by the next `start`). Dirty worktree → commit it through the worker first; `--force` only with the owner.
- **Leftovers:** `stack gc` (registry slots whose worktree is gone, idle isolated-agent worktrees), `doctor` (orphan
  worktrees), `sweep` (journaled UI writes, old runtime files), `apply --all` (staged reports and verdicts). Every
  session start already prunes old runtime files and removes clean isolated-agent worktrees idle for a day.

## Gotchas

When something behaves unexpectedly, read `references/gotchas.md` (symptom → cause → fix). **A new surprise goes
there in the same session**, in the same one-block format.
