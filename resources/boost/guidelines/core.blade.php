## Kanban (petar-spasic/laravel-kanban)

- The plan of record is the board: branch `kanban`, checked out at docs/kanban. Epics → boards → cards; decided cards on project/decisions are binding.
- Read: `vendor/bin/kanban status|list|show <ID>|context`. Change only via `vendor/bin/kanban …` (each change is a commit); editing docs/kanban is blocked.
- Code work happens only in a card's worktree `.claude/worktrees/<id>` with its own stack (`kanban stack wait`), never in the main checkout.
- Git: a subagent may `git add`/`git commit` only inside its own worktree; push, pull, fetch, stash, reset, checkout, switch, rebase, merge and worktree commands are the main session's — hooks deny them.
- Done = every acceptance criterion proven in the card's stack as the project's tests/CLAUDE.md requires, the configured gates pass (`kanban context` lists them), the evaluator approves; `finish` merges, `publish` pushes.
- New work you notice → `--discovered`; a question or a new dependency → report blocked. Never start unplanned work.
- Why: the board is how parallel agents and the owner stay in sync; work outside it is invisible and unreviewed.
- Orchestrating (main session): follow the `kanban` skill (without Boost: vendor/petar-spasic/laravel-kanban/resources/boost/skills/kanban/SKILL.md).
