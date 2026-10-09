---
name: kanban-status
description: >-
  Tells the owner in plain words what is happening on the project's kanban
  board right now: whether the board is being driven, which cards are in
  progress and which agents run on them, where approved cards stand in
  the merge queue, what merged in the last 24 hours, what is blocked and
  why, how many questions wait for the owner, and what the agents spent.
  Read-only. Use when the owner asks what is up, how the board is doing,
  what the agents are doing, or types
  /kanban-status. Triggers — kanban status, board status, what's up, what are
  the agents doing, how far are we, kanban-status.
---

# Kanban status

Read-only: never start, stop, answer or change anything here.

1. `vendor/bin/kanban morning --since=24h` (the brief, then what merged, what is blocked and the agent runs).
2. Is the board being driven? The brief's `lease:` check names the holder; the last lines of
   `.git/laravel-house/run.log` say what `kanban run` did last, and when. When approved cards wait and nothing merged,
   `vendor/bin/kanban doctor` (read-only without `--fix`): `fail finish.check names no suite` means nothing merges.
3. Answer in one short message, plain words, no command output:
   - **Driving:** yes (by which session, last action and how long ago) or no.
   - **In progress:** each card being planned, in doing or in review, one line: id, title, planner, worker or
     evaluator and for how long; how many cards wait in planning and in ready.
   - **Merge queue:** the card being merged, on which machine and since when, and whether a merger works on a
     conflict or a failed test; the approved cards waiting, in order; any held until a failure on main is fixed; cards
     the merge sent back to their worker.
   - **Merged in the last 24 hours:** count and titles.
   - **Needs the owner:** questions (`N open, M provisional`; `questions` lists them), discovered cards waiting for
     criteria, cards blocked and why, a failure on main that holds the queue, an empty `finish.check` (nothing
     merges).
   - **Spend:** agent runs, tokens and cost at list price in the last 24 hours, and per merged card.
4. Offer the next step that fits: the morning when questions wait, starting the board when nothing drives it.
