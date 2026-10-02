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
| 9 | remote | network down, or a push rejected 3 times in a row: transient, retry later with backoff; never force-push. A claim that exits 9 left no claim commit behind |
| 75 | stack still starting | run `stack wait` again |

## Actors

- `main`: `KANBAN_SESSION` set (SessionStart exports it). `owner`: a terminal without it, or the UI.
- `worker` / `evaluator`: bound by the PreToolUse hook at `EnterWorktree` (even when Claude Code then refuses the switch);
  `report` / `verdict` also require the cwd to be the card's worktree (`cd <worktree> && …` when Claude Code refused the switch).
- `--force` is main-only and logged.
- A log entry may carry `who`: the person at that keyboard (`KANBAN_USER`, else git `user.name`, else the name in an explicit `KANBAN_GIT_AUTHOR`; none known: no key). `by` stays the role, `who` is shown beside it (`owner (Ana)`, `worker (Ana)`) and is never used for claims, notes, locks or merges. An entry written by an agent carries the person whose machine ran it.

## Read (anyone)

| Command | Does |
|---|---|
| `status [--json]` | The brief: branch, unpushed, WIP, doing/review with agents and URLs, blocked, next, latest decisions, checks |
| `list [--board=E/B --stage= --type= --label= --all --json]` | Default: ready, doing, review, plus blocked anywhere |
| `show ID [--json --log=10]` | Header, body, criteria, deps with stages, claim, work, agent state, log |
| `context [ID] [--evaluate]` | For agents; card from the cwd worktree, with the `gates.report` commands (`{main_branch}` resolved). notes and stage reasons from the owner and main since the start; `--evaluate` adds the report and the card's diff stat against main |
| `next [--count=1 --json]` | Pull order, or `none: <reason>` |
| `validate [--fix]` | Schema and cross-card rules; `--fix` rewrites canonically, re-ids duplicates (one commit) |
| `doctor [--fix]` | `ok\|warn\|fail` lines, exit 1 on any fail; `--fix` re-runs attach and the install steps, then Claude Code needs a restart |
| `lease [--takeover --release]` | The orchestrator lease (15 min idle expiry); `--takeover`/`--release` run from the main checkout |

## Write (main session or owner)

| Command | Does |
|---|---|
| `new E/B "Title" [--type --priority --label=* --accept=* --depends=* --body= --body-file=- --why= --decided-on= --stage=]` | Create a card (backlog/ready for work, proposed/decided for decisions) |
| `board E/B ["Title"] [--kind=work\|decisions --order= --wip-doing=]` | Create or update a board |
| `set ID k=v…` | `title= priority= type= labels=+a,-b depends_on=+ID accept+="…" accept[2]="…" accept-=3 tick=1 untick=2 blocked="…"/"" body=@- why=@- note="…" decided_on= supersedes=+ID resolution=`; one `@-` per run. In a locked stage (`locked` in kanban.json: doing, review, done, superseded) only `note= blocked= tick= untick=` are taken (exit 3); `--force` (main) overrides |
| `move ID STAGE [--reason= --force]` · `move ID --board=E/B` | Transitions below; a board move is a `git mv` |
| `promote [ID…] [--auto]` | Backlog → ready by the ready policy; `refused ID: R4 …` lines |
| `import-house-docs [--decisions= --ideas= --board= --dry-run --strict]` | decisions.md / ideas.md → decision cards, idempotent |

## Card lifecycle (main session)

| Command | Does |
|---|---|
| `start ID [--force]` | Claim, slot, worktree `.claude/worktrees/<id without key>-<slug≤24>` (compose project `{app}-wt-` + that name) on `card/<id>-<slug≤40>`, deps copied, `.env`, `compose up -d --build` (no wait); prints the Agent spawn line |
| `refresh ID\|--all` | Merge main into the branch. Moved head → approval cleared. Conflict → card to doing, merge left in progress, exit 5, prints `SendMessage: …` |
| `finish ID` | Needs review + approval of the current head + clean worktree + no live agent. Merge `--no-ff`, done, `finish.after` commands on main, stack down, slot freed, worktree and branch removed |
| `stop ID --to=ready\|backlog\|dropped [--keep-branch --force --reason=]` | Stack down, slot freed, worktree removed (dirty, or started on another machine → refused without `--force`); a branch with commits is parked and reused by the next `start` |
| `stack [ID\|PATH] create\|up\|down\|status\|wait\|logs\|url` · `stack list` · `stack gc [--force]` | Per-worktree stack. `wait` exits 75 after 110 s. `gc`: slots whose worktree is gone, plus idle isolated-agent worktrees; `--force` also unregistered `*-wt-*` projects |
| `apply [ID\|--all]` | Apply staged reports/verdicts whose agent is gone; retry hook payloads left in `inbox/` |
| `sync` · `publish` · `sweep` · `attach` | Pull/push `kanban`; push `kanban` + `main` once per run; commit journaled UI writes and prune old runtime files (`--reclaim` also removes idle isolated-agent worktrees; SessionStart runs both); check out the board on this machine |

## Agents

| Command | Who | Does |
|---|---|---|
| `report ID --status=review\|blocked [--tick=N* --summary= --summary-file=- --verified="cmd → result"* --discovered="bug: Title — body"* --reason= --note=]` | worker, own card | Staged; applied at SubagentStop |
| `verdict ID approve\|reject --check=N:pass\|fail:"evidence"* [--issue=* --discovered="bug: Title — body"* --note=]` | evaluator, own card | Every criterion needs one check; approve needs all pass and no issues; discovered items never decide it |
| `stack status\|wait\|logs\|up` | worker, evaluator | Own worktree only; never `down` |

## Transitions

| Move | Through |
|---|---|
| backlog ↔ ready | owner or main; into ready needs the ready policy (R1 work type, R2 title, R3 body, R4 1–12 criteria, R5 deps valid and decisions decided, R6 not blocked, R7 in backlog) |
| ready → doing | `start` only |
| doing → review | an applied `report --status=review` only |
| review → doing | reject verdict, refresh conflict, or owner "send back" with a note |
| review → done | `finish` only |
| doing/review → ready/backlog/dropped | `stop` only |
| any → dropped | reason required |
| decisions: proposed → decided / dropped | `decided_on` / `resolution` required |
| decided → superseded | automatic when a decided card lists it in `supersedes` |

Pull order: capacity = min(max_parallel − doing on this host, Σ boards (wip.doing − doing)), 0 when review ≥ wip.review;
candidates ready, unblocked, unclaimed, deps satisfied, no shared `area:*` label with doing/review; sorted by priority,
epic order, board order, oldest in ready, id; `urgent` may exceed capacity by 1.

## SubagentStop (what the hook does)

- Worker without a report since its last start → blocked with the exact `report` command; after 3 blocks the card
  gets `blocked: worker stopped without report` and the stop is allowed.
- `--status=review` is also refused on a dirty worktree, zero commits beyond base, or a failing `gates.report` command.
- Applied under the lock, once per content hash: review/blocked, ticks, `work.head`, agent unbound.
- Evaluator: approve (verdict head = branch head) → `work.approved`; reject → doing, failed criteria unticked.
- Either agent's `--discovered` items become cards in the backlog of the card's board, label `discovered`.

## Recovery

| Failure | Do |
|---|---|
| Hook error | The PreToolUse hook never blocks and ignores its own errors. SubagentStop writes `inbox/` first: `apply --all` retries. `doctor` checks paths |
| UI and CLI write at once | Same lock; stale rev → 409 in the UI; every write is one commit |
| Teammate without the merge driver | `attach` / `doctor --fix` configure it; SessionStart verifies |
| Container git unusable (UI) | Writes go to the journal; the next host write, `sweep` or `sync` commits them |
| No stack slot / resources | Exit 7 names it: `stack gc`, finish or stop a card, or widen the Docker address pools |
| Main behind merged migrations | `finish` runs `finish.after` (migrate, reference seeder) and prints `rebuild main` when lockfiles or docker files changed |
