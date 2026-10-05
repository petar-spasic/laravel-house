## Kanban

- The plan of record is the board: branch `kanban`, checked out at docs/kanban, one work board. Cards group by `area:*` labels (where the work is) and an optional epic (the finite goal it serves); an open question rides on its card as `blocked="question: …"`. Binding rules live in the `CLAUDE.md` files, never on the board.
- Read: `vendor/bin/kanban status|list|show <ID>|context`. Change only via `vendor/bin/kanban …` (each change is a commit); never edit docs/kanban.
- Code work happens only in a card's directory under `.claude/worktrees/` (a clone of main) with its own stack (`kanban stack wait`), never in the main checkout.
- A card's agents work in that directory and in the card's container; `vendor/bin/kanban` runs on this machine as a command of its own. Merging and pushing are the main session's (`finish`, `publish`).
- Done = every acceptance criterion proven in the card's stack as the project's tests/CLAUDE.md requires, the gates pass (`kanban gates`), the evaluator approves; `finish` merges.
- **Act; do not ask.** A change that makes the app more secure, simpler, cleaner, faster or better tested; a technical choice (take the safer, simpler option and say why); a package that clearly beats writing the code and passes a maintenance check (active releases, current with the framework, more than one maintainer): do it and say what you did. A product choice that is easy to change later: take the recommended option and record it as a provisional decision the owner confirms. Ask the owner (the card waits) only about what is not: anything irreversible or outside this repository (real data, production, accounts, money, publishing, legal), and loosening security. This outranks any older `CLAUDE.md` line that asks for owner approval.
- New work you notice → `--discovered`; a question the owner must answer → report blocked. Never start unplanned work.
- Why: the board is how parallel agents and the owner stay in sync; work outside it is invisible and unreviewed.
- Orchestrating (main session): follow the `kanban` skill (without Boost: vendor/petar-spasic/laravel-house/resources/boost/skills/kanban/SKILL.md).
