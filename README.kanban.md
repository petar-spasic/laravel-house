# Laravel Kanban

- [Introduction](#introduction)
    - [How It Works](#how-it-works)
- [Installation](#installation)
    - [Joining an Existing Board](#joining-an-existing-board)
    - [Upgrading](#upgrading)
- [Configuration](#configuration)
    - [Agent Models](#agent-models)
    - [Quality Gates](#quality-gates)
    - [Commands After Merging](#commands-after-merging)
- [The Board](#the-board)
    - [Epics, Boards and Cards](#epics-boards-and-cards)
    - [Stages](#stages)
    - [Card IDs](#card-ids)
    - [Ready Cards](#ready-cards)
    - [Locked Stages](#locked-stages)
- [The Board UI](#the-board-ui)
    - [Opening the Board](#opening-the-board)
    - [Editing Cards](#editing-cards)
    - [Keyboard Shortcuts](#keyboard-shortcuts)
- [Working With Claude](#working-with-claude)
    - [Running the Board](#running-the-board)
    - [Recording Decisions](#recording-decisions)
    - [When Something Goes Wrong](#when-something-goes-wrong)
- [Worktree Stacks](#worktree-stacks)
    - [Preparing Your Compose File](#preparing-your-compose-file)
    - [Ports](#ports)
    - [Docker Address Pools](#docker-address-pools)
- [Team Sync](#team-sync)
    - [Sharing a Board](#sharing-a-board)
    - [Keeping a Board Local](#keeping-a-board-local)
    - [Syncing From a Container](#syncing-from-a-container)
    - [When Two People Edit the Same Card](#when-two-people-edit-the-same-card)
- [Command Reference](#command-reference)
- [Troubleshooting](#troubleshooting)
- [License](#license)

<a name="introduction"></a>
## Introduction

Laravel Kanban gives your Laravel project a kanban board that lives inside your git repository, and lets Claude Code work through it for you. It is a development-only package: nothing it adds runs in production.

The board is a set of JSON files on its own branch. You manage it from a local web page at `/kanban`, from the `vendor/bin/kanban` command, or by asking Claude. Every change to the board is a git commit, so the board has a full history and can be shared with your team like any other branch.

When Claude works on a card, the card gets its own git worktree, its own branch and its own Docker stack. Several cards can therefore be worked on at once without stepping on each other.

<a name="how-it-works"></a>
### How It Works

Every card follows the same path:

1. **You describe the work.** You add a card with a title, a description and a list of acceptance criteria. When the card is ready to be picked up, you move it to the `ready` column.
2. **Claude starts the card.** The main Claude Code session claims the card. It creates a worktree for it in `.claude/worktrees` and brings up a Docker stack for it on its own ports.
3. **A worker writes the code.** A background `kanban-worker` agent commits to the card's branch. When it is done, it reports back, and the card moves to `review`.
4. **An evaluator checks it.** A read-only `kanban-evaluator` agent checks every acceptance criterion and approves or rejects the work. Rejected work goes back to the worker.
5. **Approved work is merged.** `kanban finish` merges the branch into `main`, marks the card `done` and removes the worktree and its stack.
6. **The run is published.** At the end of a run, `kanban publish` pushes the board and `main` to your remote.

<a name="installation"></a>
## Installation

Laravel Kanban requires:

- PHP 8.3 or newer, and Laravel 12 or 13
- git 2.42 or newer
- [Claude Code](https://claude.com/claude-code), for the agent workflow
- Docker Compose v2, if you want a Docker stack per card. Without it, cards still get their own worktree.
- [Laravel Boost](https://github.com/laravel/boost), optional

You may install the package into your project using Composer:

```shell
composer require --dev petar-spasic/laravel-kanban
```

Next, run the `kanban:install` Artisan command from your project's main checkout. The `--key` option sets the prefix of your card IDs. For example, `--key=ACME` gives cards like `ACME-7K2QF9`:

```shell
php artisan kanban:install --key=ACME
```

If you would like to see what the installer will change before it changes anything, add `--dry-run`.

Finally, check that everything is wired up correctly. The `doctor` command prints `ok`, `warn` or `fail` for each check:

```shell
vendor/bin/kanban doctor
```

> [!NOTE]
> Claude Code loads agents and hooks only when a session starts. Restart Claude Code after installing.

The installer makes the following changes:

- It creates the board on a branch named `kanban` and checks it out at `docs/kanban`. This branch shares no history with your code.
- It configures git on this machine: a merge driver for the board's files, and the package's git hooks. These reject `Co-Authored-By` trailers, and pushes from a card's worktree.
- It adds hooks and a permission for `vendor/bin/kanban` to `.claude/settings.json`. Hooks that are already there are kept. It also turns off Claude Code's commit and PR attribution, because those trailers would be rejected.
- It writes the two agents to `.claude/agents/kanban-worker.md` and `.claude/agents/kanban-evaluator.md`.
- It adds the package's instructions to `CLAUDE.md`. With Laravel Boost, they arrive as a guideline and a `kanban` skill instead.
- It adds `/docs/kanban/` and `/.claude/worktrees` to `.gitignore`.

Review these changes and commit them to `main`. The settings in `.claude/settings.json` apply to everyone who clones the project.

> [!WARNING]
> The package's git hooks are installed by pointing `core.hooksPath` at them, so hooks in `.git/hooks` stop running. If `core.hooksPath` is already set, for example by Husky, it is kept and the package's hooks do not run. `vendor/bin/kanban attach --force` replaces it. To keep `Co-Authored-By` trailers and Claude Code's attribution, set `githooks.reject_co_authored` to `false` in `config/kanban.php`, then run `vendor/bin/kanban doctor --fix`.

<a name="joining-an-existing-board"></a>
### Joining an Existing Board

Each other machine or fresh clone of the project needs the board checked out and git configured. Run the following after cloning:

```shell
composer install
vendor/bin/kanban attach
```

A new Claude Code session also runs `attach` by itself when the board is missing.

<a name="upgrading"></a>
### Upgrading

To upgrade, update the package. Then let `doctor --fix` rewrite the agents, the hooks and the git config:

```shell
composer update petar-spasic/laravel-kanban
vendor/bin/kanban doctor --fix
```

If you use Laravel Boost, also run `php artisan boost:update`. Restart Claude Code once you are done.

> [!WARNING]
> Every clone that shares a board should run the same version of the package.

<a name="configuration"></a>
## Configuration

Most projects need no configuration. If you would like to change the defaults, publish the configuration file to `config/kanban.php`:

```shell
php artisan vendor:publish --tag=kanban-config
```

The most common settings may also be set in your `.env` file:

```ini
KANBAN_SYNC=auto          # auto, on or off. See "Team Sync".
KANBAN_USER=Ana           # Your name in the board's history. Defaults to git's user.name.
KANBAN_MAIN_BRANCH=main   # The branch cards are merged into.
KANBAN_MAX_STACKS=6       # How many card stacks may run on this machine at once.
KANBAN_PULL_SECONDS=30    # How often an idle board asks for other people's changes.
KANBAN_UI=true            # Set to false to turn off the /kanban page.
KANBAN_UI_TOKEN=          # Set to make the /kanban page ask for this token once per browser.
```

<a name="agent-models"></a>
### Agent Models

By default, the worker runs on Sonnet with high effort and the evaluator runs on Opus with medium effort. You may change either one in the `agents` section of `config/kanban.php`:

```php
'agents' => [
    'worker' => ['model' => 'sonnet', 'effort' => 'high'],
    'evaluator' => ['model' => 'opus', 'effort' => 'medium'],
],
```

These values are written into the agent files. After changing them, run `vendor/bin/kanban doctor --fix` and restart Claude Code.

<a name="quality-gates"></a>
### Quality Gates

A worker cannot hand in its work until every command in `gates.report` passes on its branch. By default, the only gate is Pint. You may add your own, such as your test suite or a front-end check:

```php
'gates' => [
    'report' => [
        'vendor/bin/pint --test --diff={main_branch}',
        'npm run check',
    ],
],
```

<a name="commands-after-merging"></a>
### Commands After Merging

After `finish` merges a card into `main`, it runs the commands in `finish.after` in your main checkout. They run only in projects with a [worktree stack](#worktree-stacks). By default, these run your migrations and seed reference data:

```php
'finish' => [
    'after' => [
        'php artisan migrate --force',
        'php artisan db:seed --class=ReferenceDataSeeder --force',
    ],
],
```

A `db:seed --class=…` command is skipped while that seeder does not exist.

<a name="the-board"></a>
## The Board

<a name="epics-boards-and-cards"></a>
### Epics, Boards and Cards

The board is organised in three levels. An **epic** is a large goal. It holds one or more **boards**, and each board holds **cards**. On disk, every one of them is a JSON file under `docs/kanban`:

```
docs/kanban/
├── kanban.json                 # Board-wide settings: key, WIP limits, locked stages
└── billing/                    # An epic
    ├── epic.json
    └── invoices/               # A board inside it
        ├── board.json
        └── ACME-7K2QF9.json    # A card
```

The installer creates one epic, `project`, with two boards: `project/work` for work and `project/decisions` for decisions.

A card has a type (`feature`, `bug`, `chore`, `spike` or `decision`), a priority (`urgent`, `high`, `normal` or `low`), labels, a description in Markdown, acceptance criteria and, optionally, other cards it depends on.

> [!WARNING]
> Never edit the files in `docs/kanban` by hand. Use the UI, the `kanban` command or Claude. Each of them validates the change and commits it.

<a name="stages"></a>
### Stages

Work boards move cards through these stages:

| Stage | Meaning |
|---|---|
| `backlog` | An idea, not yet ready to be worked on. |
| `ready` | Fully described and waiting to be picked up. |
| `doing` | A worker is on it, in its own worktree. |
| `review` | The worker is done; the evaluator checks the work. |
| `done` | Merged into `main`. |
| `dropped` | Not going to happen. Dropping a card asks for a reason. |

Decision boards record the decisions your project makes:

| Stage | Meaning |
|---|---|
| `proposed` | An open question with its options. |
| `decided` | Decided, with the date and the reason. Every card must follow it. |
| `superseded` | Replaced by a newer decision. |
| `dropped` | Not going to be decided. |

You move cards between `backlog` and `ready`, and to `dropped`. The rest of the path belongs to the workflow: a card enters `doing` through `start`, `review` through the worker's report, and `done` through `finish`.

<a name="card-ids"></a>
### Card IDs

A card ID is your key followed by six random characters, such as `ACME-7K2QF9`. Wherever a command asks for an ID, you may type just the start of it: at least three characters after the key, as long as they match only one card. Case does not matter, and the key may be left out:

```shell
vendor/bin/kanban show 7k2
```

<a name="ready-cards"></a>
### Ready Cards

A card may only move from `backlog` to `ready` when it is complete enough for an agent to work from:

- it is a work card with a title and a description;
- it has between 1 and 12 acceptance criteria;
- every card it depends on exists, and every decision it depends on is decided;
- it is not blocked.

Write each acceptance criterion as something the evaluator can check, such as "GET /invoices.csv lists the month's invoices". `kanban promote` tells you which rule a card misses.

<a name="locked-stages"></a>
### Locked Stages

Once work starts, the worker and the evaluator rely on the card as they read it. Cards in `doing`, `review`, `done` and `superseded` are therefore locked. You may still add a note, block or unblock the card, and tick or untick criteria. Nothing else about it can change, and it cannot move to another board.

To edit a card in `doing` or `review`, put it back first:

```shell
vendor/bin/kanban stop ACME-7K2QF9 --to=ready
```

The list of locked stages is the `locked` setting in `docs/kanban/kanban.json`.

<a name="the-board-ui"></a>
## The Board UI

<a name="opening-the-board"></a>
### Opening the Board

The board's web page is at `/kanban` in your application, for example `http://localhost:8080/kanban`. It is only available in the `local` environment and only from the main checkout.

The page shows one column per stage, with the cards in the order Claude will pick them up. It refreshes every few seconds, so changes made by Claude, the command line or a teammate appear on their own.

The page does not appear while your routes are cached, so run `php artisan route:clear` if it is missing. It needs nothing from your application: no session, login, Vite or Tailwind. It requires a browser from 2024 or later. It is set in the Inter font, which ships with the package under the SIL Open Font License.

<a name="editing-cards"></a>
### Editing Cards

Click a card to open it. Fields save on their own: the title and the criteria when you press `Enter` or leave them, checkboxes and dropdowns at once. Text such as the description opens an editor that saves with *Save* or `Ctrl+Enter`. *Saved* appears in the header. Cards it links to, such as its dependencies, open on top of it. Press `Esc` to close the top one.

To move a card, drag it to another column, or press `m`. Press `n` to add a new card.

If someone else edits the same text while you are typing, nothing is overwritten. Your text stays in the editor, the other version appears beside it, and you choose *Keep mine* or *Use theirs*.

<a name="keyboard-shortcuts"></a>
### Keyboard Shortcuts

Press `?` on the page to see every shortcut. The most useful ones are:

| Key | Action |
|---|---|
| `/` | Search |
| `j` `k` | Next or previous card |
| `h` `l` | Column to the left or right |
| `Enter` | Open the card |
| `n` | New card |
| `m` | Move the card |
| `p` | Change the priority |
| `b` | Switch board |
| `Shift+1`…`9` | Save this board to a number key |
| `Alt+1`…`9` | Go to a saved board |
| `Esc` | Close the card on top |
| `t` | Switch between light and dark |

<a name="working-with-claude"></a>
## Working With Claude

<a name="running-the-board"></a>
### Running the Board

Open Claude Code in your project's main checkout and ask it to run the board:

```
Run the board.
```

The main session follows the package's `kanban` skill, which the installer points it to. It moves complete cards from `backlog` to `ready` and starts as many cards as the limits allow. For each card it starts a worker in the background, sends finished work to the evaluator and merges what is approved. When the run is over, it publishes and sends you one summary covering:

- what was merged;
- what is still in progress;
- which cards are blocked, with the questions it needs you to answer;
- which decisions are waiting for you.

You may also ask it to work on one card, such as "start ACME-7K2QF9".

> [!NOTE]
> Only one Claude Code session per machine may run the board at a time. If an old session still holds it, for example after a restart, run `vendor/bin/kanban lease --takeover`.

<a name="recording-decisions"></a>
### Recording Decisions

Decisions are yours to make, and Claude records them. When you tell Claude a decision, it adds a card to `project/decisions` in the `decided` stage with your reason. When it meets an open question, it adds a `proposed` card and asks you. It never decides one by itself.

You may also record a decision yourself:

```shell
vendor/bin/kanban new project/decisions "Money is stored in cents" \
    --stage=decided --decided-on=2026-10-01 --why="Avoids rounding errors"
```

<a name="when-something-goes-wrong"></a>
### When Something Goes Wrong

Start with `status`. It shows the cards in progress with their agents, the blocked cards and anything that needs attention:

```shell
vendor/bin/kanban status
vendor/bin/kanban show ACME-7K2QF9
```

If a check fails, run `vendor/bin/kanban doctor`. It names the problem, and `doctor --fix` repairs the wiring.

To give up on a card, put it back. If its branch has commits, it is kept and reused the next time the card starts:

```shell
vendor/bin/kanban stop ACME-7K2QF9 --to=backlog --reason="Waiting on the payment provider"
```

<a name="worktree-stacks"></a>
## Worktree Stacks

Each card's worktree runs its own copy of your local Docker stack. It uses its own ports, containers and database, so a worker can migrate, seed and test without touching your main stack.

The stack is built from your project's `docker-compose.local.yml`. The `start` command writes a `.env` into the worktree with the stack's name and ports, then runs `docker compose up`. `finish` and `stop` take the stack down again.

<a name="preparing-your-compose-file"></a>
### Preparing Your Compose File

Because many copies of the stack run at once, nothing in your compose file may use a fixed name or a fixed host port:

```yaml
name: "${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME unset}"

services:
  app:
    build: .
    ports:
      - "${WEB_PORT:-8080}:80"
  postgres:
    image: postgres:16-alpine
    ports:
      - "${SIDECAR_BIND:-0.0.0.0}:${DB_HOST_PORT:-5432}:5432"
```

In particular:

- the top-level `name:` must use `${COMPOSE_PROJECT_NAME:?…}`, as above, so a stack never starts without a name;
- do not use `container_name`, or volume and network names that are not built from `${COMPOSE_PROJECT_NAME}`;
- every published host port must come from one of the port variables: `WEB_PORT`, `DB_HOST_PORT` or `REDIS_HOST_PORT`, or `DB_PORT` and `REDIS_PORT`, which follow them;
- a service with `build:` must not also set `image:`.

Your main `.env` also needs a project name of its own, such as `COMPOSE_PROJECT_NAME=acme-local`.

If you publish more host ports, such as for Vite or a mail catcher, add a variable for each one to `stack.ports` in `config/kanban.php`, with its offset in the card's block of ports:

```php
'ports' => ['WEB_PORT' => 0, 'DB_HOST_PORT' => 1, 'REDIS_HOST_PORT' => 2, 'VITE_PORT' => 3],
```

`vendor/bin/kanban doctor` checks all of these rules and names any line that breaks one. It also warns when `phpunit.xml` sets `DB_HOST` or `DB_PORT`, because tests in a worktree would then hit your main database.

If you do not use Docker, set `stack.compose_file` to `null`. Cards then get a worktree without a stack.

<a name="ports"></a>
### Ports

Card stacks take their ports from the range 21000–21999, in blocks of ten. The first card gets `WEB_PORT=21010`, `DB_HOST_PORT=21011` and `REDIS_HOST_PORT=21012`, the next one gets 21020 to 21022, and so on. The block is reserved across every project on the machine, so two projects never collide. Keep your main stack's ports outside that range.

`vendor/bin/kanban stack ACME-7K2QF9 url` prints a card's address.

Before starting a stack, the package also checks that the machine has room: at most `KANBAN_MAX_STACKS` stacks (6 by default), at least 8 GiB of free memory, at least 20 GiB of free disk, and a load below 75% of the CPUs.

<a name="docker-address-pools"></a>
### Docker Address Pools

Each stack is its own Docker network. If your local network overlaps Docker's default address ranges, Docker runs out of networks after about six stacks, and compose fails. You may widen the ranges once, in `/etc/docker/daemon.json`:

```json
{
    "bip": "172.17.0.1/16",
    "default-address-pools": [
        { "base": "10.210.0.0/16", "size": 24 },
        { "base": "172.16.0.0/12", "size": 16 }
    ]
}
```

Then restart Docker and bring your stacks back up:

```shell
sudo systemctl restart docker
vendor/bin/kanban doctor
```

`doctor` shows how many networks are free, and warns below six.

<a name="team-sync"></a>
## Team Sync

<a name="sharing-a-board"></a>
### Sharing a Board

If your project has an `origin` remote, the board is shared through it automatically. The installer pushes the `kanban` branch once. After that, every change is pushed after it is made, and changes from others are pulled at most every 30 seconds: while the board page is open, when a Claude Code session starts, and when `status` or `next` runs.

Teammates join by running `vendor/bin/kanban attach` in their clone. When two machines try to start the same card, only one of them gets it.

The board runs ahead of the code: `finish` marks a card `done` for everyone at once, but its code reaches your teammates only when `kanban publish` pushes `main`.

<a name="keeping-a-board-local"></a>
### Keeping a Board Local

If you would like the board to stay on your machine, add the following to `.env` before installing:

```ini
KANBAN_SYNC=off
```

Board changes then stay local until you run `vendor/bin/kanban publish`.

> [!NOTE]
> A `config/kanban.php` published by an older version may still read `env('KANBAN_SYNC', 'off')`. Change it to `'auto'` to turn sync on.

<a name="syncing-from-a-container"></a>
### Syncing From a Container

If your application runs in Docker, the board page syncs from inside the container. The container needs:

- `php` and `git` on its `PATH`;
- `exec()` allowed in PHP;
- a user that can write the mounted `.git` directory;
- permission to push to your remote.

For an ssh remote, `kanban:install`, `attach` and `doctor --fix` create a deploy key for this clone at `.git/laravel-kanban/deploy_key` and print its public half. This happens when the project has a local compose file. Ask a repository admin to add the key with write access:

```shell
gh repo deploy-key add .git/laravel-kanban/deploy_key.pub --allow-write
```

Next, point the container's git at the key in your local compose file. The key is already inside the container through the project's mount, so if your project is mounted at `/app`:

```yaml
services:
  app:
    environment:
      GIT_SSH_COMMAND: "ssh -i /app/.git/laravel-kanban/deploy_key -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"
```

Your own git on the host keeps using your own key. To see what goes wrong in the container, run `vendor/bin/kanban sync` inside it.

<a name="when-two-people-edit-the-same-card"></a>
### When Two People Edit the Same Card

If two people change the same field of a card, the newer change wins. When the field is text, such as the title or the description, the older version is kept in the card's history: open the card or run `vendor/bin/kanban show` to see it.

The history shows who made each change, such as *Ana* for what Ana did herself and *worker (Ana)* for her agents. The name comes from `KANBAN_USER`, or from git's `user.name` when it is not set.

If a sync keeps failing, the board page shows a notice, and `status` and `doctor` print the reason.

<a name="command-reference"></a>
## Command Reference

Every command runs as `vendor/bin/kanban <command>` without booting your application, or as `php artisan kanban:<command>`. The only exception is `kanban:install`, which runs through Artisan only. Add `--help` to any command for its options. The [protocol reference](resources/boost/skills/kanban/references/protocol.md) lists every flag and exit code.

**Reading the board**

| Command | Description |
|---|---|
| `status` | What is in progress, blocked and next, and any failing checks. |
| `list` | Cards in `ready`, `doing` and `review`, plus blocked cards. |
| `show ID` | One card with its criteria, dependencies and history. |
| `next` | The card that would be started next. |
| `doctor` | Checks the installation. `--fix` repairs it. |
| `validate` | Checks every board file. `--fix` rewrites them. |

**Changing the board**

| Command | Description |
|---|---|
| `new EPIC/BOARD "Title"` | Adds a card. |
| `set ID key=value` | Changes a card, such as `priority=high` or `note="…"`. |
| `move ID STAGE` | Moves a card to another stage, or `--board=EPIC/BOARD` to another board. |
| `promote ID` | Moves a card from `backlog` to `ready`. `--auto` fills `ready` with complete cards, up to 12 by default. |
| `board EPIC/BOARD "Title"` | Adds or updates a board. |

**Working on cards** (usually run by Claude)

| Command | Description |
|---|---|
| `start ID` | Claims a card and creates its worktree and stack. |
| `refresh ID` | Merges the latest `main` into the card's branch. |
| `finish ID` | Merges an approved card into `main` and cleans up. |
| `stop ID --to=STAGE` | Takes a card out of work and cleans up. |
| `stack ID up\|down\|logs\|url` | Manages a card's stack. |
| `sync` | Pulls and pushes the board. |
| `publish` | Pushes the board and `main`. |
| `attach` | Checks out the board on this machine. |

<a name="troubleshooting"></a>
## Troubleshooting

Problems met in real projects are listed in the [gotchas](resources/boost/skills/kanban/references/gotchas.md), each with its cause and its fix. The most common ones are:

- **"Agent type not found", or the hooks do not run.** Restart Claude Code after installing or upgrading.
- **`set` exits with "a locked stage".** The card is in a locked stage: `doing`, `review`, `done` or `superseded`. See [Locked Stages](#locked-stages).
- **Compose fails after about six stacks.** Widen the [Docker address pools](#docker-address-pools).
- **Tests in a worktree hit your main database.** Remove `DB_HOST` and `DB_PORT` from `phpunit.xml`.
- **The board page answers 403 for a host name.** Add that name to `ui.hosts` in `config/kanban.php`.

<a name="license"></a>
## License

Laravel Kanban is open-source software licensed under the [MIT license](LICENSE).
