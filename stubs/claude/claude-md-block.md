## Kanban

- The plan of record is the board: branch `kanban`, checked out at docs/kanban, one work board. Cards group by `area:*` labels; an open question rides on its card as `blocked="question: …"`. Binding rules live in the `CLAUDE.md` files, never on the board.
- Read: `vendor/bin/kanban status|list|show <ID>|context`. Change only via `vendor/bin/kanban …` (each change is a commit); never edit docs/kanban.
- Code work happens only in a card's directory under `.claude/worktrees/` (a clone of main) with its own stack (`kanban stack wait`), never in the main checkout.
- A card's agents work in that directory and in the card's container; `vendor/bin/kanban` runs on this machine as a command of its own. Merging and pushing are the main session's (`finish`, `publish`).
- Done = every acceptance criterion proven in the card's stack as the project's tests/CLAUDE.md requires, the gates pass (`kanban gates`), the evaluator approves; `finish` merges.
- New work you notice → `--discovered`; a question or a package outside the approved set → report blocked. Never start unplanned work.
- Why: the board is how parallel agents and the owner stay in sync; work outside it is invisible and unreviewed.
- Orchestrating (main session): follow the `kanban` skill (without Boost: vendor/petar-spasic/laravel-house/resources/boost/skills/kanban/SKILL.md).
