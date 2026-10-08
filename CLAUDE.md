# CLAUDE.md — petar-spasic/laravel-house

The owner's house package for Laravel projects. Its skills are served two ways from one copy:
- a Claude Code plugin marketplace (`.claude-plugin/`), installed at user scope to start new projects;
- a composer dev package whose skills Laravel Boost copies into a project's `.claude/skills/`.

The composer package also ships PHP to every project that requires it: the `validation:export` command, `house:update`
with the house guidelines Boost renders into a house project's root `CLAUDE.md`, and the kanban board. The board is a
git-backed board on an orphan `kanban` branch checked out at `docs/kanban`, the `vendor/bin/kanban` CLI, a local
`/kanban` UI, Claude Code hooks and agents, and one clone of main and one Docker stack per card. A project goes on the
board only through `/implement-kanban` (`kanban:install`).

Consumers read `README.md` and the skills. This file is for working **on** the package.

## Layout

| Path | Role |
|---|---|
| `resources/boost/skills/<name>/` | The only copy of each skill: `SKILL.md`, `references/`, and `templates/` or `scripts/` where a skill ships files. Boost reads this path from every direct, non-first-party dependency listed in `boost.json` `packages` |
| `.claude-plugin/marketplace.json` | The marketplace; its one plugin's `source` is the repository root (`"./"`) |
| `.claude-plugin/plugin.json` | The plugin; `"skills": "./resources/boost/skills/"` makes the same directory its skills |
| `src/LaravelHouseServiceProvider.php`, `src/Validation/*` | The `validation:export` command |
| `resources/boost/guidelines/*.blade.php`, `src/Rules/*` | The root house rules as Boost package guidelines (`@houserules`, registered by the provider, holds only with a project's `config/house.php`, read by setup's `scripts/HouseConfig.php` as `install.php` reads it); `house:update`, which runs setup's `install.php --update` |
| `src/Kanban/KanbanServiceProvider.php` | The board's provider: config, the `kanban:*` commands, the `/kanban` routes and views |
| `src/Kanban/Store/**`, `src/Kanban/Schema/*`, `schema/*.json` | Store contract, git driver (writes, batches, merge driver, sync and its status, claims, the upgrade of older formats to version 3), validation |
| `src/Kanban/Policy/*` | Transitions, ready policy, the plan (its format and when it is current), pull order, edits and creation, fold, shape (the card-cutting hints) |
| `src/Kanban/Console/*`, `src/Kanban/Console/Install/*` | CLI commands; install steps (stored-name migration, settings hooks, agents, the `CLAUDE.md` block and Boost entry, `.gitignore`) |
| `src/Kanban/Code/*` | Card clones and worktrees, their `.env`, machine-wide port registry, compose stacks, merge checks, main check and push, dependency checks |
| `src/Kanban/Upstream/*` | Package findings: scrubber, findings on cards, `gh` |
| `src/Kanban/Protocol/*`, `src/Kanban/Hooks/*` | Runtime files, staged reports and verdicts, stop gates, lease, SessionStart brief, context, the 6 hook handlers |
| `src/Kanban/Guard/Guard.php`, `bin/kanban-guard`, `githooks/*` | PreToolUse binder (binding, heartbeat, spawn record, a card agent's shell routing and file fence); commit-msg and pre-push hooks |
| `bin/kanban-exec` | The kill-safe wrapper a card agent's shell runs through into its container |
| `src/Kanban/Http/*`, `routes/web.php`, `resources/views`, `resources/dist` | Local UI: shell page, JSON API, `Presenter`, `UiGuard`; `kanban.js`/`kanban.css` (no build step, vanilla JS, CSP-safe) |
| `src/Kanban/Import/*` | House docs importer (`docs/decisions.md`, `docs/ideas.md`) |
| `config/kanban.php` | The board's defaults (`kanban.*`); a project's own `config/kanban.php` merges over it |
| `stubs/board`, `stubs/claude` | Board skeleton; hooks JSON, agent definitions and the `CLAUDE.md` block written by install |
| `bin/kanban` | The standalone CLI, `vendor/bin/kanban` in a project |
| `dev`, `docker/*` | The dev container: Dockerfile, `./dev`, the browser checks of the UI (`docker/browser`), and the build of the bundled font (`docker/fonts`) |
| `tests/E2E/*`, `tests/Support/*` | E2E suites and sandboxes (`Sandbox`, `Origin`, `CodeSandbox`, `GuardSandbox`, `ProtocolSandbox`, `UiSandbox`, `ExportSandbox`), fakes (`FakeDocker`, `FakeGh`) |
| `phpunit.xml.dist`, `pint.json` | Suite and code-style config |
| `composer.json` | The package (rule 7). Autoloads `src/`, registers both providers, ships `bin/kanban` |
| `README.md` | The one consumer doc (rule 10) |

Template rule files are stored as `CLAUDE.md.stub` (the installer drops `.stub`): a real `CLAUDE.md` under `templates/`
would load here as live instructions. Never rename one back.

## Rules

1. Be concise; comment only what can't be inferred. YAGNI and KISS. **No provenance anywhere**: no "was X, now Y",
   "taken from <project>", "previously…". Files state what is; history lives in git.
2. **No project or machine specifics** in a skill: no project names, no real IP addresses (examples use RFC 5737:
   192.0.2.x, 198.51.100.x, 203.0.113.x), no home-directory paths, no concrete host ports, no owner-personal settings
   stated as universal. One placeholder style: `{{app}}` for the project slug, `{{web_port}}`-style for values.
3. **One copy per skill**, under `resources/boost/skills/`. Never a second copy or a symlink for the plugin.
4. **Skill format:** frontmatter `name` + folded `description` ("… Use when … Triggers — …"), at most 1024 characters;
   `SKILL.md` under 300 lines; detail in `references/`, one level deep. Improving also means removing: prune what is
   redundant, stale, speculative or one-off whenever a skill is touched.
5. **One owner per topic; point, never copy.**
   - The `kanban` skill: the kanban protocol and the gotchas consumers meet. `src/Kanban`: what `kanban:install` writes.
   - `laravel-deployment`: the compose, entrypoint, Caddy, Vite and phpunit shapes, as templates and snippets that
     setup's `scripts/install.php --templates` renders; the Hosting section; the spa path split (`references/spa.md`);
     tenancy's database roles (`references/tenancy.md`).
   - `laravel-project-setup`: seeding, conventions, the PHP minor, ports, the Horizon gate; which modules combine (its
     SKILL.md Modules table and `install.php`); the core-auth and tenancy rules (the `CLAUDE.md` stubs and
     `resources/boost/guidelines`); the spa
     frontend rules (`templates/modules/spa/frontend/CLAUDE.md.stub`).
   - `src/Validation`: what `validation:export` does.
   - `README.md`: what a consumer reads, the board included. It describes and points; it never restates rule text.
6. **Boost rewrites what it copies:** a `*.blade.php` file inside a skill is rendered and saved as `.md`, and every
   `.md` file goes through its Markdown formatter. A template that must reach a project as `.blade.php` needs another
   extension here.
7. **Dependencies:**
   - require: `php` and `laravel/framework` ^12|^13 only. require-dev: `orchestra/testbench`, `pestphp/pest`,
     `laravel/pint`, and `laravel/boost` (`HouseRulesTest` renders the guidelines through it) only. conflict:
     `petar-spasic/laravel-kanban`.
   - Nothing else without the owner's OK: no JSON-schema, Markdown or JS libraries.
   - `src/` is autoloaded (`PetarSpasic\LaravelHouse\` → `src/`), and setup's `scripts/HouseConfig.php` by classmap
     (`install.php` requires it where there is no Composer); both providers are discovered through
     `extra.laravel.providers`.
   - No hosted CI and no `.github/workflows`: checks run locally before a push.
   - Commits carry no Co-Authored trailer. `githooks/commit-msg` rejects one, and this repo uses it
     (`git config core.hooksPath githooks`).
8. **Tests are E2E only**, in the projects these skills set up and in this package.
   - The package's tests run real entry points against temp git repos: `bin/kanban`, `bin/kanban-guard`,
     `bin/kanban-exec` and the git hooks through Process, HTTP through testbench routes, and hook payloads on stdin.
     Fake only outside systems: docker (`tests/Support/FakeDocker`), GitHub's CLI (`tests/Support/FakeGh`) and remotes
     (a local bare repo).
   - Fixtures use invented names (key ACME, "Acme Notes"), never a real project's names, ports or text. Tests never
     hardcode machine addresses: hosts come from the environment (`CodeSandbox::lanHost()` reads `LOCAL_APP_URL`), and
     network ranges come from `198.18.0.0/15`, because doctor counts the host's own interfaces. Docs, the README
     included, use RFC 5737 examples (rule 2).
   - Review agents do not run the tests. The implementer runs `./dev check` (and `./dev ui` for UI changes) after every
     change; a reviewer repeating them finds nothing new. Tell each review agent to read and reason, and to run only
     the narrowest reproduction that confirms a specific finding.
   - Record a surprise in the same session, as symptom → cause → fix. What consumers meet on the board goes into
     `resources/boost/skills/kanban/references/gotchas.md`, the board's only gotchas file. What only package work meets
     goes into this file. A surprise met while using another skill goes into that skill. Remove an entry once the code
     fixes it.
9. **Rules, not code.**
   - Templates ship the infrastructure (Dockerfiles, compose, Caddyfiles, entrypoints, healthcheck) and the files
     under `templates/` today: the backend files, the htmx and islands boot files, `config/boost.php` and the Boost
     overrides, the snippets, the E2E tests. They stay.
   - Every module's frontend, Socialite and tenancy ship as binding rules in the `CLAUDE.md` stubs and the guidelines.
   - Module text sits in `<!-- if:m -->` blocks, or `# if:m` … `# endif` in files that are not Markdown, or
     `@houserules('m')` … `@endhouserules` in a guideline, whose first line gates the whole topic
     (`HouseConfig::holds`; `HouseRulesTest` renders every module set through `boost:update` and checks the leaks). A
     deployment
     module's additions sit in its template blocks, and its reference keeps the why, traps and Verify, so an app without
     the module carries none of it.
   - New shipped code needs the owner's OK.
   - A package enters a template's prescribed list only after the maintenance check: its row in
     `laravel-project-setup/references/packages.md` records the date checked, the last release, the maintainers, the
     majors it supports and the fallback. Re-check every row on each pin bump.
10. **`README.md` follows the Laravel docs style.**
    - One H1, then the TOC: H2 entries at column 0, H3 entries indented four spaces.
    - `<a name="…"></a>` on the line directly above each H2 and H3.
    - Introduction first. Only `> [!NOTE]` and `> [!WARNING]`.
    - Every fence is tagged and introduced by a sentence ending in a colon.
    - Second person, short sentences, one idea each. Explain a term once, or leave it out.
    - A change to what a consumer sees updates it in the same commit.
11. **Deterministic steps are code.** A step whose result is fixed by its inputs ships as a script, command or gate with
    an E2E test: copying or rendering templates, filling placeholders, wiring hooks and settings, patching a known
    line, checking a rule that can be checked. A skill keeps only what needs judgement (merging into a file the project
    changed, choosing, wording) and calls the code. A rule that matters is a gate, not prose.

## Kanban: load-bearing constraints

Class names below are relative to `PetarSpasic\LaravelHouse\Kanban` (`src/Kanban`).

- **`bin/kanban-guard` + `src/Kanban/Guard/Guard.php` have zero dependencies.** They are loaded with `require_once` and use no Composer or Illuminate. The hook runs on tool calls, so p95 must stay under 50 ms; `tests/E2E/Guard` asserts this. It records kanban spawns (SubagentStart binds the agent to its card from that record; EnterWorktree still binds as a fallback) and refreshes the heartbeat. Kanban agents run without Claude Code's worktree isolation, whose git check would refuse their wrapped git. For a bound planner, worker or evaluator Guard does two more things, by string checks only:
  - **Routing:** every Bash and Monitor command is rewritten through `updatedInput`, never with a decision. A plain `vendor/bin/kanban` command (no shell operators outside quotes, no environment assignments; a leading `cd <dir> &&` into the card's directory is dropped) becomes main's own binary run in the card's directory, never the card's copy; every other command becomes `vendor/bin/kanban-exec <container> <cwd> '<cmd>'`. `ClaudeSettings` writes the matching allow rules to `.claude/settings.local.json` (they name this checkout's path; Claude Code applies that file to subagents too), and gates take the same two paths.
  - **The fence:** a relative path, or none, resolves in the card's directory; its file tools are denied outside it, and writes into the card's `.git` and `.claude` (a planner's writes anywhere but the card's `.tmp`); reads may also reach Claude Code's temp directory and the skill directories. This is the only thing it denies.

  Any error prints nothing. Everything else agents do is steered by their instructions (`stubs/claude/agents`, the SubagentStart context).
- **A card's directory is a clone of main** (`Worktrees::add`, `kanban.main` names main; `Paths` and Guard follow it back). Its agents run git inside their container, where main's `.git` is never mounted; no card stack gets a deploy key. Host-side code reads a clone's state only through `Git::untrusted` (no hooks, fsmonitor, filters, signing or other config of the clone runs on this machine; fetches name main by path, never the clone's remote), and moves branches with `Worktrees::sync`: main's branch into the clone, the card's branch into main. Gates run in the card's container. Other worktrees (`claude -w`, the board) stay git worktrees.
- **`bin/kanban` never boots the host app.** It is Illuminate Console standalone: `.env` via Dotenv, the project's `config/kanban.php` merged over the package config. Hooks and workers depend on this when a branch breaks the app. The same command classes run under artisan (`kanban:*`); only `kanban:install` is artisan-only (`ARTISAN_COMMANDS` in `KanbanServiceProvider`).
- **The UI needs nothing from the host app.** `/kanban` is one Blade shell (`resources/views/app.blade.php`; with `ui.token` set, `UiToken` serves `token.blade.php`, a plain GET form, until the browser holds the token) plus a JSON API under `/kanban/_api` (`_api` is not a slug, so no board can collide with it); `resources/dist/kanban.{js,css}` draw everything. Its routes run without the `web` group (`ui.middleware` defaults to `[]`): no session, cookie or CSRF token; `UiGuard` refuses cross-site requests, foreign Host names (DNS rebinding) and writes without `X-Kanban`; `UiHeaders` sends the CSP (`default-src 'self'`) that the Markdown of card text relies on. No build step, no libraries, no inline script or style, no `innerHTML` except the server-escaped Markdown (`view.innerHTML = html; /* md-sink */`). Every colour, radius and font size is a token in `:root` (`light-dark()` for the two themes; no colour literal outside it), and the coarse-pointer and forced-colours blocks are the last rules of `kanban.css`; icons are inline SVG built from the marked `ICONS` table, never glyphs or `data:` URIs (CSP). Cards open as layered panels (`S.stack`, URL `?from=`), each panel an instance from `buildPanel`; every dropdown is `openList`/`selectPill`. The script has no automated tests (tests are E2E only), so it is checked in a browser under a strict CSP; see Workflow. Reads go through `Http\Presenter`, writes through `Store`/`Transitions` with the card's rev; the shared edit rules live in `Policy\Edits` (including the lock on stages listed in `locked`) and `Policy\Creation` (the CLI uses them too).
- **Board commands are discovered,** not listed: every `src/Kanban/Console/*Command.php` extending `Console\Command` (implement `perform(): int`). `validation:export` is registered by `LaravelHouseServiceProvider`.
- **Board writes go only through `Store`** (`Store\Git\GitStore`). Each write takes a flock on `.git/laravel-house/lock`, checks the rev, validates, writes atomically in canonical JSON, and makes one commit.
  - In the container the UI commits with `--git-dir/--work-tree`; when git is unusable it falls back to `journal.jsonl`.
  - Never write board files any other way.
- **Sync has one primitive, `GitStore::sync()`:** fetch (no lock), rebase with the merge driver under the write lock, push (no lock). Every trigger ends there: the debounced runner after a write and the timed pull `maybeSync()` (UI poll, SessionStart, `next`, `status`, and each pass of `run`, which syncs in place before it acts; one ask per `pull_seconds`, shared through `sync.tick`) single-flight through `sync.lock`; the CLI (`sync`, `publish`, install) runs it directly, and a push race with a runner is settled by the loser fetching again. The rebase passes the running package's own `bin/kanban` as the driver, never the path in `.git/config` (a container cannot run the host's). A claim is won only by the push that lands: each round fetches, rebases, re-checks the card and capacity on what origin holds, builds the claim commit (the card's `work` in it) aside, in an index of its own with no branch on it, and pushes it once by its id. Only a push that lands moves the branch to it, so no other push of the clone can carry a claim that has not won; a rejected push starts over, and a push that fails with an error looks once whether origin took the commit all the same. Unpushed commits are never a reason not to pull. `SyncStatus` (`sync.status.json`) is advisory: the UI, `status` and the brief read it, and no claim, write or merge decides from it. The container syncs over ssh with the clone's own key, `.git/laravel-house/deploy_key` (`Store\Git\DeployKey`, made by `configure()`: install, attach, `doctor --fix`; ssh origin plus a local compose file only); the compose file points the container at it with `GIT_SSH_COMMAND`, and nothing points this machine's git at it (`core.sshCommand` would break a user whose own key works until an admin adds the deploy key).
- **The merge driver is a pure function of (ancestor, A, B, path)** (no clock, randomness or I/O), so two machines merging the same conflict write the same bytes. What the newer side displaced (texts, a stage/claim/work state, a cleared block) goes into the card log as `conflict` entries with content-derived ids, which the log union writes once. Every clone runs the same package version (Release).
- **`who` is for display.** A log entry's `by` stays one of the seven roles; the optional `who` (`KANBAN_USER`, else git `user.name`, else an explicit `KANBAN_GIT_AUTHOR`) is shown beside it and never decides a claim, note, lock or merge. One UI server is one person.
- **A text editor in the page settles a 409 by content, never by the newer rev alone:** the field still says what the editor started from -> save again without asking; already what was typed -> nothing to write; anything else -> show the other version beside the typed one and let the user choose. Drafts keep the text and revision they were typed against.
- **Stage transitions go only through `Policy\Transitions`.** start, apply, sendBack, finish, stop and plan are unreachable from `move`.
- **A planning card holds a claim only while a planner works on it** (`Card::atWork()`; `Stage::isActive` stays doing and review). A ready card starts only with a current plan (`Policy\Plan::current`: the `planned` entry's hash is the card's criteria and body without what `Questions::strip` takes out, the question sections and an answer confirming a Provisional decision's choice). The planner's clone and stack come down in `stop --to=ready`, never in a hook.
- **Reports, verdicts and plans are staged by the CLI** (`report`, `verdict`, `plan`) and applied at SubagentStop: the hook payload's last message is not the subagent's report.
- **Stack commands strip the host env.** `Code\Stack` removes main's `.env` keys and every `COMPOSE_*` from docker's environment, passes `-p`, and asserts `config .name`. Otherwise a worktree stack takes over the main one.
- **Branches:** the board branch is `kanban`, code branches are `card/<id>-<slug>`. `kanban/…` is impossible next to `kanban`.

## Workflow

- **Package findings** from consumers arrive as `agent-finding` issues on the repository (`kanban upstream file`).
- **Container:** `./dev` (`docker/Dockerfile`) is the one environment for the package code: PHP 8.5 (`PHP_VERSION=8.3 ./dev build` for the oldest supported), git 2.47 (the board needs ≥ 2.42, so the base is Debian trixie), Composer, and headless Chromium with Playwright's library. `vendor` lives in a named volume, so the host's PHP never matters; files written into the checkout belong to you. Nothing else is needed on the host but Docker. `./dev` alone lists the commands. Node, Chromium and `playwright-core` exist only in the image: they are not package dependencies (rule 7).
- **The font:** Inter ships as three subset files (`resources/dist/inter-<revision>-<weight>.woff2`, OFL text beside them), built by `./dev fonts` from the image. They are served `immutable` without a version query, so a changed file needs a new revision number: `revision=` in `docker/fonts/build.sh`, then the names in `kanban.css`, `Ui::ASSETS` and `app.blade.php` (the tests read the file names from the directory, so a missed one fails them).
- **Check:** `./dev check` runs the suite in parallel (about 30 s; the tests use their own temp dirs and random port pools) and `pint --test`, inside the container above. While other agents share the checkout, run pint on your own paths: `pint` and `--dirty` format every untracked file.
- **Surprises:**
  - `./dev test --parallel a.php b.php` prints paratest's usage → paratest takes one path → one file per call.
  - The suite dies loading with "Access level to …Command::ask() must be public" → Illuminate's `Command` has public
    `ask`, `info`, `line`, `table`, `confirm` and more, and a command's private method of that name clashes → name it
    otherwise (`askOwner`).
  - A signal handler that calls `exit()` in a command running a child Process kills the child → `exit()` runs
    destructors, and `Process::__destruct()` stops a running process → no handler; hold a lock the system frees instead.
  - `./dev shell -c '…'` opens a plain shell and runs nothing → `shell` passes no arguments on → run a tool on chosen
    paths with `./dev composer exec -- pint <paths>`.
  - Spend in `morning` came out several times too high → `claude -p --resume` reports `total_cost_usd` for the whole
    session so far, while `usage` is the run's own → `AgentRun` logs the difference (`session_cost_usd` beside it).
  - A test that reads the last entries of one write passes and fails at random → `Json::canonical` sorts the log by
    `at`, then `id`, and one write's entries share `at` while ids are random → select a write's entries by `at` and
    event, never by position.
- **UI changes:** the script has no unit tests, so `./dev ui` drives it in a real Chromium (CSP `default-src 'self'`) against a seeded board: `docker/browser/checks/*.mjs`, one file per area, screenshots in `build/ui`. A new interaction gets a check there (`t.ok`), written first and seen failing on the old code, because a check that passes before the fix proves nothing; the accessibility sweep (`a11y.mjs`) holds contrast (4.5:1), target size (24 px), names, tab order and, on a touch device, 16 px text boxes and 40 px controls; `forced.mjs` emulates Windows high contrast; `./dev ui serve` serves the seeded board at http://localhost:8099/kanban for looking at by hand. A check that needs another person opts into the `team` seed (`export const seed = 'team'`): an origin, the page's server running as Ana with sync on, and a second clone "peer" (Ben) that `t.cli(cmd, { root: t.seed.peer, env })` acts in.
- **`validation:export` relies on Laravel internals:** protected `Validator` methods such as `getMessage()` and
  `makeReplacements()`. After a Laravel minor upgrade, re-run the parity proof on a scratch spa app that requires this
  package by path.
- **Trying it in a consumer:** push a commit and `composer update petar-spasic/laravel-house` with the constraint `dev-main`. A path repository does not resolve inside the consumer's container; the Release smoke install runs on the host.

## Verify before every push

Each line passes or prints nothing:

```bash
# Package code
./dev check                                                     # suite and pint; `./dev ui` too after a UI change

# Package and manifests
claude plugin validate .                                        # passes; the one warning is the omitted version
claude --plugin-dir . plugin details laravel-house              # lists all five skills
composer validate --no-check-publish
for f in .claude-plugin/*.json composer.json; do php -r 'json_decode(file_get_contents($argv[1]), flags: JSON_THROW_ON_ERROR);' "$f"; done
php -r '$c = json_decode(file_get_contents("composer.json"), true); foreach ($c["extra"]["laravel"]["providers"] as $p) { $f = "src/".str_replace(["PetarSpasic\\LaravelHouse\\", "\\"], ["", "/"], $p).".php"; is_file($f) || print("✗ $p\n"); }'
find src -name '*.php' -exec php -l {} \; | grep -v '^No syntax errors'
for f in resources/boost/skills/laravel-project-setup/scripts/*.php; do php -l "$f" | grep -v '^No syntax errors'; done

# Skills
wc -l resources/boost/skills/*/SKILL.md                         # each under 300; setup under 220
for f in resources/boost/skills/*/SKILL.md; do php -r 'preg_match("/^description: >-\n((?:  .*\n)+)/m", file_get_contents($argv[1]), $m); $n = mb_strlen(preg_replace("/\s+/", " ", trim($m[1] ?? ""))); $n > 0 && $n <= 1024 || print("$argv[1]: description $n chars\n");' "$f"; done
while IFS= read -r t; do grep -qF -- "$t" resources/boost/skills/laravel-deployment/SKILL.md || echo "✗ $t"; done <<'EOF'
## Procedure
## Many stacks from one local compose
## Verify
Anyone who can reach the web port reads and edits the board at `/kanban`
The board in `/kanban` shows *Not synced* or *Not pushed*, or never shows others' changes
EOF
# ↑ titles implement-kanban quotes

# Text gates
grep -rniE "pisar|pazarko|supply|fab-portal|192\.168|/home/petar" --exclude-dir=.git --exclude-dir=vendor --exclude-dir=build . | grep -vE '^(\./)?CLAUDE\.md:|^(\./)?src/Kanban/Console/DoctorCommand\.php:[0-9]+:.*192\.168\.0\.0/16'   # no project or machine specifics; doctor's line is Docker's default pool
grep -rniE 'nginx|HMR_CLIENT_PORT|xfwd' resources README.md     # Caddy is the only web server
grep -rnE 'ssr-dev|DB_OWNER_USERNAME|VITE_REVERB|(^|[^i])/broadcasting/auth([^/]|$)|E2E_NODE_PORT|from .cn.' resources README.md   # stale names
grep -rnE 'descriptor|dist/validation|zodFromDescriptor|FormController' resources src README.md   # validation:export is the one bridge to the frontend
grep -rn 'viewPrefix' resources/boost/skills/laravel-project-setup/templates/snippets   # htmx Fortify views stay off until the project's pages exist
grep -rnE '\{\{(web_port|domain)\}\}' resources/boost/skills/laravel-project-setup/templates   # deployment's placeholders
grep -rnE 'clsx|tailwind-merge' resources README.md --exclude=packages.md   # dropped from the prescribed set

# Scripts
bash -n bin/kanban-exec || echo "✗ bin/kanban-exec"

# Boost: upstream changed none of the files the house overrides since the version setup's references/boost.md names
v=$(grep -oE 'Boost [0-9]+\.[0-9]+\.[0-9]+' resources/boost/skills/laravel-project-setup/references/boost.md | head -1 | cut -d' ' -f2)
latest=$(git ls-remote --tags --refs https://github.com/laravel/boost 'v*' | sed 's#.*/v##' | sort -V | tail -1)
[ "$v" = "$latest" ] || { d=$(mktemp -d); git clone -q --filter=blob:none --no-checkout https://github.com/laravel/boost "$d" && git -C "$d" diff --name-only "v$v" "v$latest" -- .ai/foundation.blade.php .ai/boost/core.blade.php .ai/laravel/core.blade.php .ai/pest .ai/enforce-tests.blade.php .ai/deployments .ai/laravel/skill/testing-best-practices .ai/boost/skill/infer-conventions .ai/tailwindcss config/boost.php src/Install/Agents/ClaudeCode.php | sed "s/^/✗ Boost $v → $latest changed /"; rm -rf "$d"; }   # re-base the overrides, then name the new version in boost.md

# README
grep -oE '\]\(#[a-z0-9-]+\)' README.md | sed -E 's/.*#(.*)\)/\1/' | sort -u | while read -r a; do grep -q "<a name=\"$a\"></a>" README.md || echo "✗ anchor $a"; done
grep -oE '<a name="[^"]+"' README.md | sort | uniq -d          # no duplicate anchors
awk 'prev ~ /^<a name=/ && !/^##+ / { print "✗ " prev } /^##+ / && prev !~ /^<a name=/ { print "✗ no anchor: " $0 } { prev = $0 }' README.md
```

After renaming a skill, a skill section, or a README section that skills cite, fix every hit of:

```bash
grep -rn 'laravel-deployment\|laravel-project-setup\|references/\|Many stacks\|Procedure\|Verify\|kanban` skill\|skills/kanban\|Worktree Stacks\|Preparing Your Compose File\|Docker Address Pools\|Team Sync\|Kanban Troubleshooting\|Adopting the Board\|Cutting cards\|Questions and rules\|Package findings\|Consolidating\|Judging decisions\|Where Agents Run' resources/boost README.md
```

implement-kanban's citations wrap across lines: join them (`tr '\n' ' '`) before matching.

Then run the installer for all 16 module combinations into scratch directories. Each run must exit 0 and leave only
`what_we_are_building` and `hosting`; write lint-clean PHP with no block markers, `.gitkeep`, `AcceptJson.php`,
`RequestId.php` and `SecurityHeaders.php`; record tenancy in `config/house.php` exactly when it is on, and leave nothing
for `--update --check`; write exactly `frontend/CLAUDE.md` with spa and no spa text without it. The guidelines' module text is `HouseRulesTest`'s.
Four invalid combinations are refused, and `--render-to` writes nothing into the target:

```bash
inst=resources/boost/skills/laravel-project-setup/scripts/install.php
sets=(--set app=acme --set laravel_version=13 --set php_version=8.5 --set pest_version=5)
for fe in '' htmx htmx,islands spa; do for rv in '' reverb; do for tn in '' tenancy; do
  m=$(IFS=,; a=($fe $rv $tn); echo "${a[*]}"); d=$(mktemp -d); touch "$d/artisan"
  out=$(php "$inst" "$d" --modules="$m" "${sets[@]}" 2>&1) || { echo "✗ exit [$m]"; rm -rf "$d"; continue; }
  grep 'placeholders left' <<<"$out" | grep -vE ': (what_we_are_building, hosting|hosting, what_we_are_building)$' && echo "✗ placeholders [$m]"
  find "$d" -name '*.php' -exec php -l {} \; | grep -v '^No syntax errors' && echo "✗ lint [$m]"
  grep -rlE '<!-- (if|unless):|<!-- endif' "$d" && echo "✗ markers [$m]"
  grep -rlE 'clsx|tailwind-merge' "$d" && echo "✗ dropped package [$m]"
  [ -f "$d/database/data/.gitkeep" ] && [ -f "$d/app/Http/Middleware/AcceptJson.php" ] || echo "✗ shipped files [$m]"
  [ -f "$d/app/Http/Middleware/RequestId.php" ] && [ -f "$d/app/Http/Middleware/SecurityHeaders.php" ] || echo "✗ middleware [$m]"
  grep -rlE '^\s*# (if|unless):|^\s*# endif' "$d" --include=*.php && echo "✗ hash markers [$m]"
  if [ -n "$tn" ]; then grep -q "'tenancy'" "$d/config/house.php" || echo "✗ tenancy not recorded [$m]"
  else grep -rliE 'tenan(t|cy)' "$d" && echo "✗ tenancy text [$m]"; fi
  php "$inst" "$d" --update --check || echo "✗ update [$m]"
  if [ "$fe" = spa ]; then [ "$(cd "$d" && find frontend -type f)" = frontend/CLAUDE.md ] || echo "✗ frontend/ is not rules only [$m]"
  else [ -e "$d/frontend" ] && echo "✗ frontend/ [$m]"; grep -rliE 'sveltekit|adapter-node|/api/auth' "$d" && echo "✗ spa text [$m]"; fi
  rm -rf "$d"
done; done; done
for m in islands htmx,spa islands,spa bogus; do d=$(mktemp -d); touch "$d/artisan"
  out=$(php "$inst" "$d" --modules="$m" "${sets[@]}" 2>&1) && echo "✗ accepted [$m]"
  [ "$m" != islands,spa ] || grep -q 'spa excludes' <<<"$out" || echo "✗ spa message"
  rm -rf "$d"; done
r=$(mktemp -d); t=$(mktemp -d); touch "$t/artisan"
php "$inst" "$t" --modules=spa "${sets[@]}" --render-to="$r" >/dev/null || echo "✗ render-to exit"
[ "$(ls -A "$t")" = artisan ] || echo "✗ render-to wrote into the target"
for p in "$r"/snippets/*.php; do php -l "$p" >/dev/null 2>&1 || echo "✗ snippet $p"; done
grep -rlE '<!-- (if|unless):|<!-- endif' "$r" && echo "✗ render-to markers"; rm -rf "$r" "$t"
```

A new placeholder joins `sets` here and setup's step 4; `InstallScriptTest` fails on one its skill does not name.

Then render laravel-deployment for every module combination, through the same script. Each render leaves no
placeholder or marker, passes `bash -n`, `caddy adapt` (the prod Caddyfile in FrankenPHP's image) and
`docker compose config`, and carries a module's text exactly when the module is on:

```bash
inst=resources/boost/skills/laravel-project-setup/scripts/install.php
tpl=resources/boost/skills/laravel-deployment/templates
dsets=(--set app=acme --set app_name=Acme --set php_version=8.5 --set web_port=8000 --set db_port=5433 --set redis_port=6380 --set ws_port=8001 --set domain=example.com)
for fe in '' htmx htmx,islands spa; do for rv in '' reverb; do for tn in '' tenancy; do
  m=$(IFS=,; a=($fe $rv $tn); echo "${a[*]}"); r=$(mktemp -d); t=$(mktemp -d); touch "$t/artisan"
  out=$(php "$inst" "$t" --templates="$tpl" --modules="$m" "${dsets[@]}" --render-to="$r" 2>&1) || { echo "✗ exit [$m] $out"; rm -rf "$r" "$t"; continue; }
  [ "$(ls -A "$t")" = artisan ] || echo "✗ render-to wrote into the target [$m]"
  grep 'placeholders left' <<<"$out" && echo "✗ placeholders [$m]"
  grep -rn '{{' "$r" && echo "✗ braces [$m]"
  grep -rnE '<!-- (if|unless):|<!-- endif|^\s*# (if|unless):|^\s*# endif' "$r" && echo "✗ markers [$m]"
  for f in "$r"/docker/*.sh; do bash -n "$f" || echo "✗ bash $f [$m]"; [ -x "$f" ] || echo "✗ not executable $f [$m]"; done
  for f in "$r"/snippets/*.php "$r"/tests/*.php; do [ -e "$f" ] || continue; php -l "$f" >/dev/null 2>&1 || echo "✗ lint $f [$m]"; done
  docker run --rm -v "$r:/app:ro" caddy:2 caddy adapt --config /app/docker/Caddyfile.local --adapter caddyfile >/dev/null 2>&1 || echo "✗ Caddyfile.local [$m]"
  docker run --rm -v "$r:/app:ro" -e APP_PUBLIC_PATH=/app/public -e CADDY_SERVER_ADMIN_HOST=localhost -e CADDY_SERVER_ADMIN_PORT=2019 -e CADDY_SERVER_LOG_LEVEL=INFO -e CADDY_SERVER_LOGGER=json -e CADDY_SERVER_SERVER_NAME=:8080 dunglas/frankenphp frankenphp adapt --config /app/docker/Caddyfile --adapter caddyfile >/dev/null 2>&1 || echo "✗ Caddyfile [$m]"
  printf 'COMPOSE_PROJECT_NAME=acme-local\nREVERB_APP_KEY=k\n' > "$r/.env"; printf 'APP_URL=https://example.com\nDB_PASSWORD=x\nDB_OWNER_PASSWORD=y\n' > "$r/.env.prod"
  (cd "$r" && HOME=/nonexistent docker compose -f docker-compose.local.yml config -q) || echo "✗ local compose [$m]"
  (cd "$r" && docker compose --env-file .env.prod -f docker-compose.yml config -q) || echo "✗ prod compose [$m]"
  rm "$r/.env" "$r/.env.prod"
  case ",$m," in *,tenancy,*) [ -f "$r/docker/postgres/roles.sql" ] || echo "✗ roles.sql [$m]" ;; *) grep -rliE 'tenan(t|cy)|acme_app|pgsql_owner|DB_OWNER|roles\.sql' "$r" && echo "✗ tenancy text [$m]" ;; esac
  case ",$m," in *,reverb,*) grep -q 'reverb:start' "$r/docker/healthcheck.sh" || echo "✗ reverb missing [$m]" ;; *) grep -rli 'reverb' "$r" && echo "✗ reverb text [$m]" ;; esac
  case ",$m," in *,spa,*) [ -f "$r/docker/e2e.sh" ] || echo "✗ e2e.sh [$m]" ;; *) grep -rliE 'sveltekit|adapter-node|\bssr\b|frontend/|playwright|APP_E2E|8090' "$r" && echo "✗ spa text [$m]" ;; esac
  rm -rf "$r" "$t"
done; done; done
```

## Release

Tag `vX.Y.Z` (`git tag -a`) and push the branch and the tag. One tag covers the skills, `src/` and the board. Every
clone of a shared board runs the same version, because the merge driver must write the same bytes on each.
`plugin.json` carries no `version`, so plugin users follow the default branch; adding one would freeze them on it until
the next bump. Consumers update as the README's Updating section says.

A release that changes the core is a new minor. A 0.x caret never crosses a minor, so consumers cross it with
`composer require --dev petar-spasic/laravel-house` and no constraint (README, Updating).

`src/Kanban/Console/Install/Migrate.php` moves projects off the old stored names, and `fold-boards`
(`FoldBoardsCommand`, `Store/Git/Upgrade.php`, `Archive.php`, the legacy layout in `GitStore::load()` and
`BoardRef::$legacyEpic`) moves older boards onto version 3. Delete them, their tests, the README warnings about
`petar-spasic/laravel-kanban` and older board formats, and implement-kanban's cases (d) and (e) and "Consolidating", in
the first minor after every project runs v0.7.0 or later.

Before tagging a release that touches `src/`, `bin/` or `composer.json`, smoke-install it on the host into a scratch
Laravel app outside the repo, with `house` set to this repository's path. Export `XDG_STATE_HOME` to a scratch
directory first: `doctor --fix` and the stack commands otherwise act on this machine's real port registry
(`~/.local/state/laravel-house`, and the old-name one `Migrate` moves), which other projects' stacks share:

```bash
composer config repositories.house path "$house"
composer require --dev petar-spasic/laravel-house:@dev
php artisan list | grep validation:export                       # listed
php artisan list | grep kanban:install                          # listed
php artisan list | grep house:update                            # listed
vendor/bin/kanban --version                                     # prints "kanban laravel-house"
```
