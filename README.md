# Laravel House

- [Introduction](#introduction)
- [Installation](#installation)
    - [Installing the Plugin](#installing-the-plugin)
    - [Installing the Composer Package](#installing-the-composer-package)
    - [Using Both](#using-both)
- [Starting a Project](#starting-a-project)
    - [The Questions](#the-questions)
    - [What Gets Installed](#what-gets-installed)
    - [After Setup](#after-setup)
- [Modules](#modules)
    - [Blade and htmx](#htmx-module)
    - [Svelte Islands](#islands-module)
    - [SvelteKit App](#spa-module)
    - [API-Only](#api-only)
    - [Reverb](#reverb-module)
    - [Multi-Tenancy](#tenancy-module)
    - [Combining Modules](#combining-modules)
- [What Every Project Gets](#what-every-project-gets)
    - [Postgres and Redis](#postgres-and-redis)
    - [Seed Data](#seed-data)
    - [Octane and Horizon](#octane-and-horizon)
    - [Authentication](#authentication)
    - [End-to-End Tests](#end-to-end-tests)
    - [Prefixed IDs](#prefixed-ids)
    - [Rules Per Layer](#rules-per-layer)
- [Deployment](#deployment)
    - [The Local Stack](#the-local-stack)
    - [The Production Image](#the-production-image)
    - [Running the Stack](#running-the-stack)
- [Exporting Validation Rules](#exporting-validation-rules)
- [The Kanban Board](#the-kanban-board)
    - [Adopting the Board](#adopting-the-board)
    - [Joining an Existing Board](#joining-an-existing-board)
- [Kanban Configuration](#kanban-configuration)
    - [Agent Models](#agent-models)
    - [Quality Gates](#quality-gates)
    - [The Merge Queue](#the-merge-queue)
- [The Board](#the-board)
    - [Boards and Cards](#boards-and-cards)
    - [Stages](#stages)
    - [Card IDs](#card-ids)
    - [Planned Cards](#planned-cards)
    - [Locked Stages](#locked-stages)
- [The Board UI](#the-board-ui)
    - [Opening the Board](#opening-the-board)
    - [Editing Cards](#editing-cards)
    - [Keyboard Shortcuts](#keyboard-shortcuts)
- [Working With Claude](#working-with-claude)
    - [Running the Board](#running-the-board)
    - [Your Morning](#your-morning)
    - [Cutting Cards](#cutting-cards)
    - [Questions and Rules](#questions-and-rules)
    - [Reporting Package Issues](#reporting-package-issues)
    - [When Something Goes Wrong](#when-something-goes-wrong)
- [Worktree Stacks](#worktree-stacks)
    - [Where Agents Run](#where-agents-run)
    - [Preparing Your Compose File](#preparing-your-compose-file)
    - [Ports](#ports)
    - [Docker Address Pools](#docker-address-pools)
- [Team Sync](#team-sync)
    - [Sharing a Board](#sharing-a-board)
    - [Keeping a Board Local](#keeping-a-board-local)
    - [Syncing From a Container](#syncing-from-a-container)
    - [When Two People Edit the Same Card](#when-two-people-edit-the-same-card)
- [Kanban Commands](#kanban-commands)
- [Kanban Troubleshooting](#kanban-troubleshooting)
- [Updating](#updating)
    - [Updating the Plugin](#updating-the-plugin)
    - [Updating the Composer Package](#updating-the-composer-package)
    - [Adopting the Current Core](#adopting-the-current-core)
- [License](#license)

<a name="introduction"></a>
## Introduction

Laravel House is a set of skills for [Claude Code](https://claude.com/claude-code). A skill is a set of instructions
that Claude loads when a task needs it. These skills start a Laravel project on one fixed set of rules, called "the
house", and run it in Docker.

You tell Claude what you are building. Claude asks a few questions and installs the packages. Then it writes a
`CLAUDE.md` rule file for the app and one for each layer directory, such as `app/Models`. Claude follows those rules
in every later session.

There are five skills:

- `laravel-project-setup` starts a project, or brings an older house project up to date.
- `laravel-deployment` runs the project in Docker, locally and in production.
- `implement-kanban` puts the project on the [kanban board](#the-kanban-board).
- `kanban` runs the board, once the project is on it.
- `kanban-status` tells you what is happening on the board right now.

The composer package also ships two tools:

- the [kanban board](#the-kanban-board): the `vendor/bin/kanban` command and a web page at `/kanban`;
- the [`validation:export`](#exporting-validation-rules) Artisan command.

The package is a dev dependency. Nothing it adds runs in production.

The house ships rules, not frontend code. You get no login pages, no UI components and no SvelteKit files. Each rule
file says what your project must build and how. Your project builds each piece when it needs it.

> [!NOTE]
> The house is opinionated. Every project uses Postgres, Redis, Octane, Horizon, Fortify, Socialite and Sanctum, and
> end-to-end tests only. When your project needs something the house does not cover, Claude asks you instead of
> guessing.

<a name="installation"></a>
## Installation

You may install the skills in two ways. Both serve the same copy of each skill.

- The **plugin** gives you the skills in every directory on your machine. Use it to start new projects.
- The **composer package** pins the skills to one project, at the version in its `composer.lock`. Only the package
  brings the board and `validation:export`.

You need:

- Claude Code;
- PHP 8.3 or newer, with Composer;
- Laravel 12 or 13, for the composer package;
- Node with npm;
- git 2.42 or newer;
- Docker with Compose v2;
- the `gh` CLI, if Claude should create the GitHub repository.

<a name="installing-the-plugin"></a>
### Installing the Plugin

Install the plugin once per machine, at user scope:

```shell
claude plugin marketplace add petar-spasic/laravel-house
claude plugin install laravel-house@laravel-house --scope user
```

Plugin skills carry the plugin's name as a prefix, for example `/laravel-house:laravel-project-setup`.

<a name="installing-the-composer-package"></a>
### Installing the Composer Package

The setup skill adds the package to every project it starts. To add it to another project, require it as a dev
dependency:

```shell
composer require --dev petar-spasic/laravel-house
```

Next, add `"petar-spasic/laravel-house"` to the `packages` list in `boost.json`, then run Boost's update:

```shell
php artisan boost:update
```

[Laravel Boost](https://github.com/laravel/boost) is Laravel's toolkit for AI agents. Its update copies each skill
into the project's `.claude/skills/` directory. These copies have no prefix, for example `/implement-kanban`. The
package's house rules join your `CLAUDE.md` only in a project set up on them, one with a `config/house.php`.

Laravel registers the package's commands and routes on its own. There is nothing to configure. The board waits until
you [adopt it](#adopting-the-board).

<a name="using-both"></a>
### Using Both

> [!WARNING]
> With the plugin and the package both installed, a project sees every skill twice.

The project's own copy is the pinned one. Turn the plugin off for that project:

```shell
claude plugin disable laravel-house@laravel-house --scope project
```

The setup skill does this for you.

<a name="starting-a-project"></a>
## Starting a Project

Open Claude Code in an empty directory, or in a fresh Laravel app, and run the setup skill:

```text
/laravel-house:laravel-project-setup
```

If the directory has no Laravel app or no git repository yet, the skill creates them.

<a name="the-questions"></a>
### The Questions

Claude asks you these questions together:

- **Frontend:** Blade and htmx, htmx with Svelte islands, a SvelteKit app, API-only, or something else.
- **Reverb:** realtime broadcasting. The default is no.
- **Multi-tenancy:** the default is no.
- **Slug:** a short name in lowercase letters and digits, such as `acme`. It names the test database (`acme_test`),
  the config file (`config/acme.php`) and the dev accounts' email domain.
- **Product name.**
- **Git remote:** an `origin` URL, or a new private GitHub repository.
- **Production host name**, if you know it.
- **What you are building.** You may leave this open.

Claude settles three things itself and shows them to you:

- the PHP minor version;
- free ports on your machine for the web server, Postgres and Redis, plus Reverb with that module (with the SvelteKit
  app, Reverb shares the web port);
- the LAN URL, for opening the app from another machine.

The house has no rules for another frontend, such as React, Vue or Inertia. If you pick one, Claude asks you for its
rules before it writes any code.

<a name="what-gets-installed"></a>
### What Gets Installed

Claude shows you one batch: the composer and npm packages, the files it will delete, and the decisions so far. You
approve it once. Nothing outside the batch is installed.

The installer then writes the rule files and the few files the house ships:

- the [prefixed-id](#prefixed-ids) trait;
- a middleware that makes Fortify answer with JSON until your auth pages exist;
- the middleware that gives each request an id for the logs, and the one that sets the security headers;
- the four [seeders](#seed-data);
- the Horizon gate, which decides who may open the Horizon dashboard;
- the Boost config and the house's changes to Boost's guidelines;
- end-to-end tests for sign-in, two-factor authentication, Horizon access and seeding;
- the htmx boot files, with htmx;
- the islands boot file and island component, with islands.

<a name="after-setup"></a>
### After Setup

After the install, the deployment skill puts the project in Docker. Claude then commits, with your OK.

Claude ends with two lists: what was decided, and what is still open. Type `/implement-kanban` to record both on the
[board](#the-kanban-board). If the command is not found, run `/reload-skills` first.

<a name="modules"></a>
## Modules

A module is an optional part of the house. The frontend module decides how pages reach the browser. Reverb and
tenancy add to any frontend. You choose modules once, at setup.

<a name="htmx-module"></a>
### Blade and htmx

Laravel renders the pages with Blade. [htmx](https://htmx.org) swaps parts of a page without a full reload.

- Your project caches public pages, both as whole pages and as fragments. The rules say how.
- Links prefetch when the pointer hovers over them.
- Locally, a saved CSS or JS change shows in the browser at once, on the app's own URL.

You build the auth pages yourself, as Blade views: login, registration, password reset, the two-factor challenge,
password confirmation, email verification and passkeys. The rules in `resources/CLAUDE.md` say how. Until the pages
exist, Fortify serves no pages and answers with JSON only.

<a name="islands-module"></a>
### Svelte Islands

An island is a Svelte 5 component inside a Blade page. Use one where a page needs state in the browser.

- Components come from [shadcn-svelte](https://shadcn-svelte.com). You add each one when an island first needs it.
- One set of design tokens styles both the islands and the Blade pages.

<a name="spa-module"></a>
### SvelteKit App

Two apps live in one repository. Laravel is an API-only backend. A [SvelteKit](https://svelte.dev/docs/kit) app
lives in `frontend/` and runs on Node.

- Setup writes only `frontend/CLAUDE.md`. You create the app yourself, following it. It names the allowed packages
  and what the first frontend change must deliver.
- Pages render on the server by default. A page whose data is known at build time is prerendered, which means it is
  built once into a static HTML file.
- Both apps share one origin, so there is no CORS. The web server sends Laravel's paths (the API, Sanctum, Horizon,
  the health check) to Laravel, and every other path to SvelteKit. The exact list is in the deployment skill's
  [spa reference](resources/boost/skills/laravel-deployment/references/spa.md).
- Browsers sign in with Sanctum's session cookie. Fortify answers under `/api/auth`, and your API lives under
  `/api/v1`.
- The rules cover shadcn-svelte components, forms built with Superforms and
  [`validation:export`](#exporting-validation-rules), and Playwright browser tests.

> [!NOTE]
> The production image cannot build until `frontend/` exists. The local stack runs without it. Laravel's paths, such
> as `/up` and `/api/v1`, answer. Every other path fails until the app exists.

<a name="api-only"></a>
### API-Only

Without a frontend module, Laravel serves JSON under `/api/v1`. Clients sign in with a Sanctum API token.

<a name="reverb-module"></a>
### Reverb

[Reverb](https://reverb.laravel.com) is Laravel's WebSocket server. It pushes broadcast events to the browser. The
module adds a Reverb process and the rules for events. Choose it only when a screen needs live updates.

<a name="tenancy-module"></a>
### Multi-Tenancy

> [!NOTE]
> Tenancy is opt-in, and it ships as rules your project implements, not as code. An app without it gets none of the
> rules or database roles below.

A tenant is one customer, such as a company, sharing the app with other customers. The house supports one kind of
tenancy:

- One database. Every row a tenant owns has a `tenant_id`.
- No subdomain per tenant, no database per tenant, and no tenant in the URL.
- The current tenant comes from the session or from the API token. A user who belongs to several tenants switches
  between them. An API client holds one token per tenant.

The tenant is enforced twice. An Eloquent scope adds it to every query. Postgres row-level security (RLS) makes the
database itself refuse other tenants' rows. A record of another tenant answers 404.

With tenancy, the app connects to Postgres as a role that does not own the tables and cannot bypass RLS. Migrations
run as the owner. The deployment skill's [tenancy reference](resources/boost/skills/laravel-deployment/references/tenancy.md)
sets up both roles.

<a name="combining-modules"></a>
### Combining Modules

Pick one frontend:

| Frontend | Modules |
|---|---|
| API-only | none |
| Blade and htmx | `htmx` |
| htmx with Svelte islands | `htmx`, `islands` |
| SvelteKit app | `spa` |

Then add `reverb`, `tenancy`, both or neither. Islands need htmx, and the SvelteKit app excludes htmx and islands.
Setup refuses any other combination.

<a name="what-every-project-gets"></a>
## What Every Project Gets

Whatever modules you choose, you get the following.

<a name="postgres-and-redis"></a>
### Postgres and Redis

- Postgres holds the data. Redis holds the cache, the queues and the sessions.
- The same drivers run locally and in production. There is no SQLite.
- Tests run queues inline and keep the cache and sessions in memory.
- Tests use their own database, `<slug>_test`.

<a name="seed-data"></a>
### Seed Data

Four seeders each have one job:

- `ReferenceDataSeeder` holds the data the app needs everywhere.
- `DevSeeder` holds the local dev accounts and sample data.
- `ProductionSeeder` creates the production accounts: the operator account and the admins.
- `DatabaseSeeder` picks between them.

Every seeder is safe to run twice. The production image seeds reference data on every boot, and the accounts only
when `DATABASE_SEED=true`.

<a name="octane-and-horizon"></a>
### Octane and Horizon

- [Octane](https://laravel.com/docs/octane) keeps the app in memory between requests. Production runs it on
  [FrankenPHP](https://frankenphp.dev), a PHP server built on Caddy.
- [Horizon](https://laravel.com/docs/horizon) runs every queue and shows them on a dashboard at `/horizon`.
- The dashboard admits the admins listed in `ADMIN_EMAILS`, once their email is verified. Locally it also admits
  requests from your own machine.

<a name="authentication"></a>
### Authentication

Every project gets three Laravel auth packages:

- [Fortify](https://laravel.com/docs/fortify) handles sign-in, registration, password reset, two-factor
  authentication and passkeys. It is the backend; your frontend builds the pages.
- [Socialite](https://laravel.com/docs/socialite) signs users in with Google, GitHub and other providers. It is
  installed but not wired. The rules say how to build it when you turn a provider on.
- [Sanctum](https://laravel.com/docs/sanctum) protects the API. With the SvelteKit app, the browser uses its session
  cookie. With every other module, the API accepts tokens only, and pages use normal web routes.

Password confirmation accepts a password or a passkey, nothing else. A user who signed up through a provider has no
password, so they set one through "Forgot password".

A passkey sign-in skips the two-factor challenge. If your project needs the challenge there too, the rules show how
to refuse passkey sign-in for users with two-factor on, or to send them to the challenge after the passkey.

> [!NOTE]
> Locally, passkeys work only when you open the app at `http://localhost:<web port>`, never at a LAN address.

<a name="end-to-end-tests"></a>
### End-to-End Tests

- Every test drives a whole flow through its real entry point. Pest HTTP tests live in `tests/E2E`. The SvelteKit app
  adds Playwright browser tests.
- Only paid or outside services are faked.
- There are no unit tests. The house replaces Boost's unit-test guidance.

<a name="prefixed-ids"></a>
### Prefixed IDs

Every model id looks like `inv_7Kq2mZp9Xt4LwRc1`: a short prefix for the model, an underscore, and 16 letters and
digits. This is the style Stripe uses.

- Migrations use `prefixedId()` and `foreignPrefixedId()`.
- The `users` table keeps Laravel's integer id.

<a name="rules-per-layer"></a>
### Rules Per Layer

- The root `CLAUDE.md` holds what you are building, the hosting, and the rules for the whole project.
- Each layer directory, such as `app/Models` or `routes`, has its own `CLAUDE.md`. Claude reads it when it works
  there.
- A change to the folder layout, the auth model or the routes updates your part of the matching rule file in the same
  commit.

The house rules stay current. Setup records your modules and versions in `config/house.php`, and every
`composer update` renders the rules again from it: Boost writes the root rules, and `php artisan house:update` the
house part of each layer file. Where your own rules go, and how to override a house rule, is in your root
`CLAUDE.md`, under "Where the docs live".

To remove the package from a house project, first take the `house:update` line out of `post-update-cmd` in
`composer.json`: Composer runs that list after the removal too.

<a name="deployment"></a>
## Deployment

The deployment skill copies Docker templates into your project: Dockerfiles, compose files, web server configs and
entrypoint scripts. It also fills the Hosting section of your root `CLAUDE.md`.

There are two tiers: a local stack for development and a production image. A stack is the set of containers that runs
the app. In each tier:

- One app container runs the web server, the scheduler and Horizon.
- Postgres and Redis run in their own containers beside it.
- [Caddy](https://caddyserver.com) is the web server.

<a name="the-local-stack"></a>
### The Local Stack

The local stack is your dev environment. You do not run `php artisan serve` or `composer run dev`.

- Caddy hands PHP requests to php-fpm, which runs a fresh PHP process for each request. So Xdebug breakpoints always
  fire, unlike under Octane.
- Caddy sends the dev-server paths to Vite, or to SvelteKit with the SvelteKit app. So hot reload works.
- Your source code is mounted into the container. Composer and npm install again on start when a lockfile changes.
- To open the app from another machine, set `LOCAL_APP_URL` in `.env` to the URL that machine uses, such as
  `http://192.0.2.10:<web port>`. Then run `docker compose -f docker-compose.local.yml up -d` again.
- The kanban board can run one stack per git worktree, from the same compose file. A worktree is an extra checkout of
  the repository in its own directory. See [Worktree Stacks](#worktree-stacks).

> [!WARNING]
> The local stack listens on your LAN by default, and the board at `/kanban` has no login. Set `WEB_BIND=127.0.0.1`
> in `.env` to keep the stack on your machine, or set `KANBAN_UI_TOKEN` to protect the board. Either one also keeps a
> card's agent, which works inside its own container, from editing cards through your main stack's board page. The
> page cannot approve or finish a card. Your main stack's database and Redis listen on `127.0.0.1` only, out of the
> containers' reach.

<a name="the-production-image"></a>
### The Production Image

- FrankenPHP runs Octane as a non-root user. With the SvelteKit app, a Node process renders the pages behind the same
  Caddy.
- TLS ends at a reverse proxy on your server. The image publishes its port on `127.0.0.1` only.
- In `.env.prod`, set `OCTANE_WORKERS` to a number that fits your server's memory.
- In `.env.prod`, set `TRUSTED_PROXIES` to your reverse proxy's address, as the container sees it. That is usually
  the Docker network's gateway. With the SvelteKit app, also add `127.0.0.1`. The example leaves it empty, and the
  container refuses to start until it is set.

<a name="running-the-stack"></a>
### Running the Stack

You may start the local stack and check its health with:

```shell
docker compose -f docker-compose.local.yml up -d --build --wait
docker compose -f docker-compose.local.yml exec app healthcheck.sh
```

Open the app at `http://localhost:<web port>`. Claude picks the web port at setup, and the Hosting section of your
`CLAUDE.md` names it.

To run the tests inside the stack:

```shell
docker compose -f docker-compose.local.yml exec app php artisan test
```

One test run uses the test database at a time. A second run waits until the first one ends.

With the SvelteKit app, browser tests run only through this script:

```shell
docker compose -f docker-compose.local.yml exec app docker/e2e.sh
```

The production image reads `.env.prod`. Copy the example, fill it in, then start the image:

```shell
cp .env.prod.example .env.prod
docker compose --env-file .env.prod up -d --build --wait
```

When a container starts but serves nothing, check the Traps in the
[deployment skill](resources/boost/skills/laravel-deployment/SKILL.md#traps). Each one names a symptom, its cause and the
fix.

<a name="exporting-validation-rules"></a>
## Exporting Validation Rules

This command is for the SvelteKit app. Your FormRequests stay the one place where validation rules live, and the
server stays the authority. The command turns the rules into [Zod](https://zod.dev) schemas, so a form shows the same
errors in the browser before it is sent.

Mark a FormRequest with the attribute and a name:

```php
use PetarSpasic\LaravelHouse\Validation\ExportValidation;

#[ExportValidation('register')]
final class RegisterRequest extends FormRequest
{
    // ...
}
```

Then run the export:

```shell
php artisan validation:export
```

- Each marked request becomes one TypeScript file in `frontend/src/lib/validation/generated/`, such as
  `register.ts`. It holds a Zod schema, plus the messages and field names of each locale.
- Rules that need the database or the user, such as `unique`, `exists`, closures and custom rule objects, stay on the
  server. The file lists them in a comment. Their errors reach the form through Laravel's 422 response.
- An array whose keys are names you choose is a map, such as `settings` with one entry per setting. Name it on the
  attribute, `#[ExportValidation('preferences', maps: ['settings'])]`, or the file treats it as a list.
- When a rule cannot be translated, the command fails and names the class, the field and the rule.
- Commit the generated files. `php artisan validation:export --check` fails when they no longer match the requests,
  and when an exported form has no parity spec at `frontend/e2e/parity/<name>.spec.ts`.
  The frontend rules make `npm run check` run it first.

> [!NOTE]
> The command ships in this dev package. In production the attribute does nothing, so an install without dev
> dependencies validates as usual.

<a name="the-kanban-board"></a>
## The Kanban Board

The kanban board lives inside your git repository, and Claude agents work through its cards. The board is a set of
JSON files on its own branch. You manage it from the web page at `/kanban`, from `vendor/bin/kanban`, or by asking
Claude. Every change is a git commit, so the board has a full history, and your team shares it like any other branch.

Each card Claude works on gets its own clone of the repository, its own branch and its own Docker stack. So several
cards are worked on at once without stepping on each other.

Every card follows the same path:

1. **You describe the work.** You, or Claude, add a card with a title, a description, an area and a list of
   acceptance criteria. When the card is complete enough to work from, it moves to the `planning` column.
2. **A planner plans it.** A `kanban-planner` agent reads the code in the card's own clone and Docker stack, and writes
   the plan the worker follows: the files, the steps with a check for each, and how each criterion is proven. The card
   moves to `ready`.
3. **Claude starts the card.** The main Claude Code session claims the card. It creates a clone for it in
   `.claude/worktrees` and brings up a Docker stack for it on its own ports.
4. **A worker writes the code.** A background `kanban-worker` agent follows the plan and commits to the card's branch.
   When it is done, it reports back, and the card moves to `review`.
5. **An evaluator checks it.** A read-only `kanban-evaluator` agent checks the branch as it is against every
   acceptance criterion, and approves or rejects the work. Rejected work goes back to the worker.
6. **Approved work goes through the merge queue.** One merge runs at a time for the whole project. In a merge clone
   with its own Docker stack, the queue merges `main` and the card, runs the gates and your test suite, then pushes
   `main` and marks the card `done`. A conflict or a failing test goes to a `kanban-merger` agent first. See
   [The Merge Queue](#the-merge-queue).
7. **The run is published.** At the end of a run, `kanban publish` pushes the board to your remote.

Requiring the package does not put a project on the board. The `/implement-kanban` skill does:

- It runs the installer, below.
- It archives the decisions your project has made, and puts each open question on the card that waits for it.
- It prepares one Docker stack per card.
- It runs a first card through the agent loop.

> [!NOTE]
> Only you can start this skill, by typing `/implement-kanban`. Claude never starts it on its own.

<a name="adopting-the-board"></a>
### Adopting the Board

The skill runs the `kanban:install` Artisan command from your project's main checkout. The `--key` option sets the
prefix of your card IDs. For example, `--key=ACME` gives cards like `ACME-7K2QF9`:

```shell
php artisan kanban:install --key=ACME
```

First, `--check` lists what the installer needs from your project and machine, such as git 2.42 or later, a reachable
`origin` that is not empty, and Docker Compose. It changes nothing. A `fail` line also stops the install itself. To
see what the installer will change before it changes anything, add `--dry-run`. Then check the wiring. The
`doctor` command prints `ok`, `warn` or `fail` for each check:

```shell
vendor/bin/kanban doctor
```

The installer makes the following changes:

- It creates the board on a branch named `kanban` and checks it out at `docs/kanban`. This branch shares no history
  with your code.
- It configures git on this machine: a merge driver for the board's files, and the package's git hooks. The hooks
  reject `Co-Authored-By` trailers, and pushes from a card's worktree.
- It adds hooks and a permission for `vendor/bin/kanban` to `.claude/settings.json`. Hooks that are already there are
  kept. It also turns off Claude Code's commit and PR attribution, because the hooks would reject those trailers.
- It adds the permissions that name this checkout's path, for its `vendor/bin/kanban` and `vendor/bin/kanban-exec`, to
  `.claude/settings.local.json`. That file stays out of git, so each machine gets its own.
- It writes the four agents to `.claude/agents/kanban-planner.md`, `.claude/agents/kanban-worker.md`,
  `.claude/agents/kanban-evaluator.md` and `.claude/agents/kanban-merger.md`.
- It writes a marked `## Kanban` block into the root `CLAUDE.md`, before Boost's guidelines when they are there. The
  block points Claude at the `kanban` skill.
- With Boost, it makes sure `petar-spasic/laravel-house` is in the `packages` list in `boost.json`, so
  `php artisan boost:update` copies the `kanban` skill into `.claude/skills/`. Without Boost, the block points at the
  skill inside `vendor/petar-spasic/laravel-house`.
- With an ssh `origin` and a local compose file, it creates a deploy key for this clone at
  `.git/laravel-house/deploy_key`. See [Syncing From a Container](#syncing-from-a-container).
- It adds `/docs/kanban/`, `/.claude/worktrees` and `/.claude/settings.local.json` to `.gitignore`.
- It adds the card-stack lines to a local compose file written before them. See
  [Where Agents Run](#where-agents-run).

Before your first card can merge, set `finish.check` to your test suite, as [The Merge Queue](#the-merge-queue)
says. Review these changes, commit them to `main` and run `vendor/bin/kanban publish`: the queue merges onto your
remote's `main`, so a commit left only on yours makes it diverge. The settings in `.claude/settings.json` apply to
everyone who clones the project.

> [!NOTE]
> Claude Code loads agents and hooks only when a session starts. Restart Claude Code after installing.

> [!WARNING]
> The package's git hooks work by pointing `core.hooksPath` at them, so hooks in `.git/hooks` stop running. If
> `core.hooksPath` is already set, for example by Husky, it is kept and the package's hooks do not run.
> `vendor/bin/kanban attach --force` replaces it. To keep `Co-Authored-By` trailers and Claude Code's attribution, set
> `githooks.reject_co_authored` to `false` in `config/kanban.php` before you install. On a project already installed,
> set it, run `vendor/bin/kanban doctor --fix`, and remove the `attribution` object from `.claude/settings.json`
> yourself.

<a name="joining-an-existing-board"></a>
### Joining an Existing Board

Each other machine, and each fresh clone, needs the board checked out, git configured, and the permissions that name
its own path in `.claude/settings.local.json`. Run the following after cloning:

```shell
composer install
vendor/bin/kanban attach
```

A new Claude Code session also runs `attach` by itself when the board is missing.

<a name="kanban-configuration"></a>
## Kanban Configuration

Most projects need no configuration. To change the defaults, publish the configuration file to `config/kanban.php`:

```shell
php artisan vendor:publish --tag=kanban-config
```

You may also set the most common settings in your `.env` file:

```ini
KANBAN_SYNC=auto          # auto, on or off. See "Team Sync".
KANBAN_USER=Ana           # Your name in the board's history. Defaults to git's user.name.
KANBAN_MAIN_BRANCH=main   # The branch cards are merged into.
KANBAN_MAX_STACKS=12      # How many card stacks may run on this machine at once.
KANBAN_PULL_SECONDS=30    # How often an idle board asks for other people's changes.
KANBAN_UI=true            # Set to false to turn off the /kanban page.
KANBAN_UI_TOKEN=          # Set to make the /kanban page ask for this token once per browser.
KANBAN_GIT_TOKEN=         # An https remote only: the token the local container syncs the board with.
KANBAN_AGENT_SHELL=container  # Set to host to run agents' commands on this machine instead of in their stack.
KANBAN_UPSTREAM=false     # Set to true to let Claude file package issues. See "Reporting Package Issues".
```

<a name="agent-models"></a>
### Agent Models

By default, the planner runs on Opus with high effort, the worker on Sonnet with high effort, and the evaluator and
the merger on Opus with medium effort. You may change any of them in the `agents` section of `config/kanban.php`:

```php
'agents' => [
    'planner' => ['model' => 'opus', 'effort' => 'high'],
    'worker' => ['model' => 'sonnet', 'effort' => 'high'],
    'evaluator' => ['model' => 'opus', 'effort' => 'medium'],
    'merger' => ['model' => 'opus', 'effort' => 'medium'],
],
```

`kanban run` passes these values to every agent it starts as `--model` and `--effort`, and names them on the line that
says it started the agent. It reads them again for each agent, so a change applies from the next agent it starts, with
no restart. The model and effort of the session that runs it never reach its agents.

The values are also written into the agent files, which decide for agents your session spawns itself. After changing
them, run `vendor/bin/kanban doctor --fix` and restart Claude Code.

Agents that `kanban run` starts have nobody to approve a tool. They may edit their card's files and run commands in
its container. Besides that, they may use only the tools in `allowed_tools`, which by default are WebFetch and
WebSearch. An empty list keeps them off the web:

```php
'agents' => [
    'allowed_tools' => [],
],
```

<a name="quality-gates"></a>
### Quality Gates

A worker cannot hand in its work until every command in `gates.report` passes on its branch. The gates run in the
card's stack. By default, they are Pint, a check of new migration timestamps, a check that every `id` in `database/data`
stays unless the branch adds a migration, and `npm run check` in `frontend/` when the project has one. A gate with `when` runs only where that path exists, and `timeout` gives it more than the default
120 seconds:

```php
'gates' => [
    'timeout' => 120,
    'report' => [
        'vendor/bin/pint --test --diff={main_branch}',
        'vendor/bin/kanban migrations --base={main_branch}',
        ['run' => 'vendor/bin/kanban data-ids --base={main_branch}', 'when' => 'database/data'],
        ['run' => 'cd frontend && npm run check', 'when' => 'frontend/package.json', 'timeout' => 300],
    ],
],
```

Agents run them with `vendor/bin/kanban gates`, and the [merge queue](#the-merge-queue) runs them again on the merged
code. Publishing `config/kanban.php` replaces the whole `gates` list, so copy the defaults you keep.

<a name="the-merge-queue"></a>
### The Merge Queue

Approved cards wait in the merge queue, oldest approval first. One merge runs at a time for the whole project, across
every machine that shares the board. Each merge:

1. takes the merge lease on the board, so no other machine merges at the same time;
2. brings your main checkout up to `main` on your remote, then merges the card's approved commit into that `main`;
3. runs the quality gates and your test suite on the merged code, in a merge clone with its own Docker stack;
4. pushes `main` to your remote, marks the card `done`, and removes the card's clone, stack and branch;
5. brings your main checkout and its stack up to the new `main`.

The queue never changes the card's branch. A commit the worker adds after the approval is not merged: the approval
lapses, and the evaluator checks the branch again. Without a remote, the queue merges into your local `main`, which
must be checked out in the main checkout with no uncommitted changes. An untracked file where the card adds one also
stops it.

The queue merges nothing until `finish.check` names your whole test suite. It runs where the gates run: in the merge
stack's app container, or on your machine with `KANBAN_AGENT_SHELL=host`. List each command as you would type it
there, without `docker compose exec`. Each command may run for 30 minutes:

```php
'finish' => [
    'check' => [
        'php artisan test --compact',
    ],
],
```

The queue reads the gates and `finish.check` from `config/kanban.php` on `main`, never from the card. A card that
changes them takes effect from the next merge on.

When the merge conflicts, or a gate or test fails on the merged code, a `kanban-merger` agent takes over in the merge
clone. The queue keeps the lease meanwhile. The merger ends with one of four results:

- **resolved**: it resolved the conflict, and the checks run again.
- **fixed**: the two sides broke each other, and it fixed that. The checks run again.
- **back**: the failure is the card's own, or the merger cannot tell what the card meant. The card goes back to its
  worker with the merger's note.
- **main**: the failure is already on `main`, as below.

The merger may not edit `.claude/` or `config/kanban.php`, delete a test, or skip one. A conflict in `.claude/` or
`config/kanban.php` blocks the card for you instead. Send it back with a reason
(`vendor/bin/kanban move ID doing --reason="…"`), run `vendor/bin/kanban refresh` on it, resolve a `.claude/` file in
its clone yourself, and unblock it. Its worker concludes the merge. A merge that still fails after three merger
rounds goes back to the worker.

When a test command fails on the merged code, the queue first runs it on `main` alone, in the same stack, when the card
changed no lockfile, docker file or compose file. If it fails there too, `main` is red. The queue files one
high-priority `main red: <command>` card, or adds a note to the open one, and merges nothing more until `main` moves.
The card that found it keeps its approval and waits. The card filed for the red `main` merges once its own command
passes.

A card merges only on the machine that built it, because card branches never leave their machine. When another
machine's card heads the queue, your machine waits. Once that card has headed the queue for 15 minutes, as your machine
sees it, your machine merges its own cards first.

A machine that stops while it holds the lease lets go of it after 15 minutes without a heartbeat. When your remote
rejects the push, `main` moved in the meantime: the queue merges again on the new `main`, up to three rounds in all.

After the push, the queue fast-forwards your main checkout. It installs your dependencies when the merge changed
`composer.lock` or a `package-lock.json`. In projects with a [worktree stack](#worktree-stacks), it then runs the
`migrate` command and the commands in `finish.after`, read from `main`. They run in your main stack's app container,
and the queue starts the stack first when it is down. By default, they seed reference data:

```php
'migrate' => 'php artisan migrate --force',

'finish' => [
    'after' => [
        'php artisan db:seed --class=ReferenceDataSeeder --force',
    ],
],
```

A `db:seed --class=…` command is skipped while that seeder does not exist. When the merge changed a lockfile, a docker
file or the compose file, the queue rebuilds your main stack before these steps. It builds the images first, while
your stack keeps serving, then recreates the containers. If that fails, it prints the command to run. Pass
`--no-rebuild` to `finish` to skip it.

The merging machine does the same before each merge, and `kanban run` does it on every other machine at most every two
minutes, so your main checkout keeps up with `main`. A step that fails never holds the queue. The card stays done,
`finish` exits with code 10, and `kanban run` reports each failure to Claude. A failed install skips `migrate` and
`finish.after`.

`kanban run` drives the queue for you. Without it, `vendor/bin/kanban finish` merges this machine's next card. To give
up a merge that waits for its merger, or one a failure stopped, abort it. The card stays approved and queued:

```shell
vendor/bin/kanban finish ACME-7K2QF9 --abort
```

Abort refuses while a `finish` still runs. Each test command ends after 30 minutes at most. To end a `finish` that
`kanban run` started at once, `stop` the card: it gives the merge up the same way and takes the card out of review.
Neither runs while `main` is being pushed.

<a name="the-board"></a>
## The Board

<a name="boards-and-cards"></a>
### Boards and Cards

The installer creates one board, `work`. On disk, each card is a JSON file under `docs/kanban`:

```text
docs/kanban/
├── kanban.json                 # Board-wide settings: key, WIP limits, locked stages
├── decisions.md                # Decisions recorded before questions moved onto cards (read-only)
├── _epics/
│   └── passkey-login.json      # An epic: title, goal, done-when
└── work/                       # The board
    ├── board.json
    └── ACME-7K2QF9.json        # A card
```

A card has a type (`feature`, `bug`, `chore` or `spike`), a priority (`urgent`, `high`, `normal` or `low`), labels,
a description in Markdown, acceptance criteria and, optionally, other cards it depends on. Two things group cards:

- **The area** is a label such as `area:billing`: a permanent part of the product. Every card has one, and two cards
  on the same area never run at the same time. The board gives each area a colour of its own (the first ten never
  share one), and an area keeps its colour as new ones come.
- **The epic** is a finite goal, such as `passkey-login`, with a title, a goal and the conditions that finish it. A
  card belongs to at most one epic. `kanban epic` lists the epics and how many of their cards are done.

Click an area or an epic on a card to show only its cards; click it again to show all of them. The filter stays in
the page address, so you can share a filtered board.

> [!WARNING]
> Never edit the files in `docs/kanban` by hand. Use the UI, the `kanban` command or Claude. Each of them validates the
> change and commits it.

<a name="stages"></a>
### Stages

Cards move through these stages:

| Stage | Meaning |
|---|---|
| `backlog` | An idea, not yet ready to be worked on. |
| `planning` | Fully described; a planner agent writes its plan. |
| `ready` | Planned, and waiting for a worker. |
| `doing` | A worker is on it, in its own worktree. |
| `review` | The worker is done; the evaluator checks the work, and approved work waits for the merge queue. |
| `done` | Merged into `main`. |
| `dropped` | Not going to happen. Dropping a card asks for a reason. |

You move cards from `backlog` to `planning`, back to `backlog`, and to `dropped`. A card enters `ready` through its
plan. The rest of the path belongs to the workflow: a card enters `doing` through `start`, `review` through the
worker's report, and `done` through the [merge queue](#the-merge-queue).

<a name="card-ids"></a>
### Card IDs

A card ID is your key followed by six random characters, such as `ACME-7K2QF9`. Wherever a command asks for an ID,
you may type just its start: at least three characters after the key, as long as they match only one card. Case does
not matter, and you may leave the key out:

```shell
vendor/bin/kanban show 7k2
```

<a name="planned-cards"></a>
### Planned Cards

A card may leave the `backlog` only when it is complete enough for an agent to work from:

- it has an `area:` label, a title and a description;
- it has between 1 and 24 acceptance criteria;
- every card it depends on exists;
- it is not blocked, and has no open question.

Write each acceptance criterion as something the evaluator can check, and name the test that proves it, such as
"`GET /invoices.csv` lists the month's invoices (`tests/E2E/InvoiceExportTest.php`)". `kanban promote` tells you which
rule a card misses.

Write the description for a person, in short, plain sentences: what changes and why. Each criterion holds one outcome.
`kanban new` and `kanban set` print a hint for a sentence or a criterion over 25 words. The kanban skill's
`references/planning.md` holds the rules and an example.

The card then waits in `planning`. Once the cards it depends on are done, a planner agent takes it, in a clone and a
stack of its own. The planner reads the code, checks every name against it, and writes the plan for the worker: the
files to read and change, the steps in order with a check for each, how each criterion is proven, and the traps. Then
the card moves to `ready`.

The worker is a smaller model, so the plan is short and exact. It says what to build and where, and leaves the code to
the worker. `kanban plan` refuses a plan that names a file the code does not have or leaves a criterion out. When a plan
runs long, writes the code itself, or names a `docker` command the worker cannot run inside its container, it prints a
hint, and the planner revises it.

A plan holds while the card stays as it was planned. A ready card goes back to `planning` when you change its criteria
or description, or answer one of its questions other than by confirming the option its plan took. A card that comes
back from a question is planned again too: its planner reads your answer and any work so far, and plans the rest.

If you already have a plan for a card, give it one yourself. The file needs the same sections:

```shell
vendor/bin/kanban plan ACME-7K2QF9 --plan-file=plan.md
```

<a name="locked-stages"></a>
### Locked Stages

Once work starts, the worker and the evaluator rely on the card as they read it. So cards in `doing`, `review` and
`done` are locked. You may still add a note, block or unblock the card, and tick or untick criteria. You may also
reword a criterion with a reason, which the agents see:

```shell
vendor/bin/kanban set ACME-7K2QF9 accept[2]="The export lists the month's invoices" --reason="The owner narrowed it"
```

Nothing else about it can change, and it cannot move to another board.

To edit a card in `doing` or `review`, put it back first:

```shell
vendor/bin/kanban stop ACME-7K2QF9 --to=ready
```

A card with work on its branch goes on to `planning`, where its planner plans the rest.

The list of locked stages is the `locked` setting in `docs/kanban/kanban.json`.

<a name="the-board-ui"></a>
## The Board UI

<a name="opening-the-board"></a>
### Opening the Board

The board's web page is at `/kanban` in your app, for example `http://localhost:<web port>/kanban`. It is available
only in the `local` environment and only from the main checkout. To keep it off your LAN, see
[The Local Stack](#the-local-stack).

The page shows one column per stage, with the cards in the order Claude will pick them up. It refreshes every few
seconds, so changes made by Claude, the command line or a teammate appear on their own.

- The page does not appear while your routes are cached. Run `php artisan route:clear` if it is missing.
- It needs nothing from your app: no session, login, Vite or Tailwind.
- It needs a browser from 2024 or later.
- It is set in the Inter font, which ships with the package under the SIL Open Font License.

<a name="editing-cards"></a>
### Editing Cards

Click a card to open it. Fields save on their own: the title and the criteria when you press `Enter` or leave them,
checkboxes and dropdowns at once. Text such as the description opens an editor that saves with *Save* or
`Ctrl+Enter`. *Saved* appears in the header. Cards it links to, such as its dependencies, open on top of it. Press
`Esc` to close the top one.

To move a card, drag it to another column, or press `m`. Press `n` to add a new card.

A planned card shows its plan under the acceptance criteria, folded. A card waiting for your answer shows a
*Question* tag, and its panel shows the question. Write the answer in the
description, then press *Unblock*. A *Blocks N* tag marks a card that N open cards wait on: if they are one piece of
work, fold them into it.

If someone else edits the same text while you are typing, nothing is overwritten. Your text stays in the editor, the
other version appears beside it, and you choose *Keep mine* or *Use theirs*.

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

```text
Run the board.
```

The main session follows the `kanban` skill. The routine runs in code, in `vendor/bin/kanban run`, which the session
keeps going in the background:
- it moves complete cards from `backlog` to `planning`, and has a planner plan each one;
- it starts as many planned cards as the limits allow, with a worker for each; planners and workers share the limit;
- it sends finished work to the evaluator, and merges approved cards through the [merge queue](#the-merge-queue);
- a card that waits on your answer goes back to `backlog`, and the board carries on with the others. Once you answer,
  its kept branch starts again ahead of new cards.

Each agent is a headless Claude Code session of its own. This needs Linux and card containers (see
[Where Agents Run](#where-agents-run)). The main session steps in only when something needs judgment:
a blocked card, a failing merge, a red `main`, a package finding, or nothing left to start. When it hits your usage
limit, the board pauses and resumes later. When you tell Claude to wrap up, `vendor/bin/kanban drain` makes the run
finish the cards in flight and start no new ones, without stopping it.

To see what is happening at any time, ask Claude:

```text
/kanban-status
```

To stop, tell Claude to wrap up. It starts nothing new, lets the cards in progress finish, publishes and sends you one
summary.

> [!NOTE]
> Only one Claude Code session per machine may run the board at a time. A session that ends lets go of the board, and
> after `/compact` or a restart the new session takes over from the old one of the same conversation. If a session that
> crashed, or started before you updated the package, still holds it, run `vendor/bin/kanban lease --takeover`, or wait
> 15 minutes. One merge runs at a time for the whole project, and a machine that stops while it merges lets go of the
> merge after 15 minutes.

<a name="your-morning"></a>
### Your Morning

Once a day, ask Claude for the morning:

```text
Do the morning.
```

Claude shows what merged, what is blocked and what the agents spent, then asks every open question as a multiple
choice, with a recommended answer and what each option means. Your answers go onto the cards, and the waiting cards
move on. The cards the agents discovered wait for criteria, which you and Claude write. A failure the agents find
already on `main` becomes one high-priority card, however many agents meet it. The morning lists it until you give it
an area, so `promote` can take it. Tell Claude about new work, and
the board carries on.

<a name="cutting-cards"></a>
### Cutting Cards

Cards are written for agents. Each card costs an agent to plan it, an agent to build it, a third to review it, clones,
stacks and a merge, so a card is one cohesive piece of work, not one small step. Cards that share an area never run at the same
time, so related work on one area belongs on one card, and separate areas run side by side.

When two cards turn out to be one piece of work, fold them:

```shell
vendor/bin/kanban fold ACME-B7Q2PX --into=ACME-A1K8ZT
```

`new` and `set` print a `hint:` when a card's area already has an open card, or when many cards wait on one.

<a name="questions-and-rules"></a>
### Questions and Rules

Decisions about what the product does are yours. A choice that is easy to change later does not wait for you: the
agent takes the recommended option, records it as a provisional decision, and you confirm or change it in the morning.
Anything else, such as money, legal text, real data or production, waits in `backlog` until you answer. Your answer
goes onto the card under an `## Owner answer` heading. Claude's own notes on a card are information, never your
decision.

A rule that every card must follow, such as "money is stored in cents", goes into the `CLAUDE.md` file of the
directory it governs. Every agent reads those files.

<a name="reporting-package-issues"></a>
### Reporting Package Issues

When a worker or an evaluator meets a problem in this package itself, it records it on the card. List these findings
with `vendor/bin/kanban upstream`. With `KANBAN_UPSTREAM=true` and the GitHub CLI signed in to github.com, Claude files
each one as an issue on this package's repository, after searching for an open one:

```shell
vendor/bin/kanban upstream file ACME-7K2QF9:3H8D2K1Q
```

Claude files a problem it meets itself the same way:

```shell
vendor/bin/kanban upstream new "Finish ignores the lease — seen after a restart"
```

Both commands refuse any text that names your project: its key, app name, hosts, repository, paths, addresses or
people. Without the setting, Claude lists the findings in its summary instead.

<a name="when-something-goes-wrong"></a>
### When Something Goes Wrong

Start with `status`. It shows the cards in progress with their agents, the blocked cards and anything that needs
attention. `show` gives one card in full:

```shell
vendor/bin/kanban status
vendor/bin/kanban show ACME-7K2QF9
```

If a check fails, run `vendor/bin/kanban doctor`. It names the problem, and `doctor --fix` repairs the wiring.

To give up on a card, put it back. This ends the agent that `kanban run` started for it. If the card's branch has
commits, the branch is kept and reused the next time the card starts. A draining board does not start it again:

```shell
vendor/bin/kanban stop ACME-7K2QF9 --to=backlog --reason="Waiting on the payment provider"
```

<a name="worktree-stacks"></a>
## Worktree Stacks

Each card's clone runs its own copy of your local Docker stack. It has its own ports, containers and database, so a
worker can migrate, seed and test without touching your main stack.

The stack is built from your project's `docker-compose.local.yml`. The `start` command writes a `.env` into the
clone with the stack's name and ports, then runs `docker compose up`. The merge queue and `stop` take the stack
down again.

The [merge queue](#the-merge-queue) runs one more stack on each checkout, for its merge clone in
`.claude/worktrees/_merge`. It stays up between merges and goes down when this machine has no approved card left to
merge. To see its address or its logs, name it `_merge`:

```shell
vendor/bin/kanban stack _merge url
```

<a name="where-agents-run"></a>
### Where Agents Run

A card's agents work in its clone and in its stack's container. Their shell commands run inside the container, git
included, so tests, Artisan, npm and browser checks run where your app does. A plain `vendor/bin/kanban` command runs
on your machine. Their file tools reach only the card's clone. The agents are your own: these limits catch their
mistakes, and they are no wall against an agent that tries to get out.

The local compose file mounts the card's clone at its own path too, gives the card a `TMPDIR` inside it, and keeps the
deploy key out of card stacks:

```yaml
services:
  app:
    volumes:
      - ./:/app
      - ./:${KANBAN_WORKTREE_PATH:-/app}
    environment:
      TMPDIR: ${KANBAN_TMPDIR:-/tmp}
```

`vendor/bin/kanban doctor --fix` adds these lines to a compose file written before them. Set `KANBAN_AGENT_SHELL=host` to
run agents' commands on your machine instead.

<a name="preparing-your-compose-file"></a>
### Preparing Your Compose File

Many copies of the stack run at once, so nothing in your compose file may use a fixed name or a fixed host port. The
deployment skill's local compose file already follows these rules:

- the top-level `name:` is `"${COMPOSE_PROJECT_NAME:?…}"`, so a stack never starts without a name;
- no `container_name`, and no volume or network name that is not built from `${COMPOSE_PROJECT_NAME}`;
- every published host port comes from a variable, such as `WEB_PORT`, `DB_HOST_PORT` or `REDIS_HOST_PORT`;
- a service with `build:` does not also set `image:`.

Your main `.env` also needs a project name of its own, such as `COMPOSE_PROJECT_NAME=acme-local`.

The app container runs as `HOST_UID` and `HOST_GID` (1000 by default), because it writes your checkout and its `.git`.
They must be the ids of the user who owns the checkout, `0` when Claude Code runs as root. `vendor/bin/kanban doctor`
checks them, `doctor --fix` writes them into `.env`, and `start` refuses a card while they differ. Rebuild your main
stack after a change.

Each card's stack gets a block of ten ports. `stack.ports` in `config/kanban.php` places the three above in it. Any
other host port variable in your compose file, such as `${MAILPIT_PORT:-8025}` for a mail catcher, takes the next free
port of the block, so you list nothing.

`vendor/bin/kanban doctor` checks these rules and names any line that breaks one. It also warns when `phpunit.xml`
sets `DB_HOST` or `DB_PORT`, because tests in a worktree would then hit your main database.

If you do not use Docker, set `stack.compose_file` to `null`. Cards then get a worktree without a stack, and the
merge queue merges nothing, because it runs your test suite in a stack of its own.

<a name="ports"></a>
### Ports

Card stacks take their ports from the range 21000–21999, in blocks of ten. The first card gets `WEB_PORT=21010`,
`DB_HOST_PORT=21011` and `REDIS_HOST_PORT=21012`, the next one gets 21020 to 21022, and so on. The block is reserved
across every project on the machine, so two projects never collide. Keep your main stack's ports outside that range.

`vendor/bin/kanban stack ACME-7K2QF9 url` prints a card's address.

Before it starts a stack, the package checks that the machine has room: at most `KANBAN_MAX_STACKS` stacks (12 by
default, room for six cards in doing and six in review, which keep their stacks), at least 8 GiB of free memory, at
least 20 GiB of free disk, and a load below 75% of the CPUs. The merge stack is outside these limits, so a busy
machine still merges.

<a name="docker-address-pools"></a>
### Docker Address Pools

Each stack is its own Docker network. If your local network overlaps Docker's default address ranges, Docker runs out
of networks after about six stacks, and compose fails. You may widen the ranges once, in `/etc/docker/daemon.json`:

```json
{
    "bip": "172.17.0.1/16",
    "default-address-pools": [
        { "base": "10.210.0.0/16", "size": 24 },
        { "base": "172.16.0.0/12", "size": 16 }
    ]
}
```

Then restart Docker, check the headroom, and bring your stacks back up:

```shell
sudo systemctl restart docker
vendor/bin/kanban doctor
```

`doctor` shows how many networks are free, and warns below `KANBAN_MAX_STACKS` plus one for the merge stack.

<a name="team-sync"></a>
## Team Sync

<a name="sharing-a-board"></a>
### Sharing a Board

If your project has an `origin` remote, the board is shared through it. The installer pushes the `kanban` branch
once. After that, every change is pushed as soon as it is made. Changes from others are pulled at most every 30
seconds: while the board page is open, when a Claude Code session starts, when `status` or `next` runs, and while
`kanban run` drives the board.

Teammates join with `vendor/bin/kanban attach`, as in [Joining an Existing Board](#joining-an-existing-board). When
two machines try to start the same card, only one of them gets it.

Every merge pushes `main`, so your teammates get a card's code as soon as the card is `done`.

<a name="keeping-a-board-local"></a>
### Keeping a Board Local

To keep the board on your machine, add the following to `.env` before installing:

```ini
KANBAN_SYNC=off
```

Board changes then stay local until you run `vendor/bin/kanban publish`. The merge queue still pushes `main` on
every merge while the project has an `origin`.

> [!NOTE]
> A published `config/kanban.php` that reads `env('KANBAN_SYNC', 'off')` keeps sync off. Change the default to
> `'auto'` to turn sync on.

<a name="syncing-from-a-container"></a>
### Syncing From a Container

When your app runs in Docker, the board page syncs from inside the container. The container needs:

- `php` and `git` on its `PATH`;
- `exec()` allowed in PHP;
- a user that can write the mounted `.git` directory;
- permission to push to your remote.

For an ssh remote, `kanban:install`, `attach` and `doctor --fix` create a deploy key for this clone at
`.git/laravel-house/deploy_key` and print its public half. This happens when the project has a local compose file.
Ask a repository admin to add the key with write access:

```shell
gh repo deploy-key add .git/laravel-house/deploy_key.pub --allow-write
```

The container's git must use that key. The key is already inside the container through the project's mount. The
deployment skill's local compose file sets this for you. In another compose file with the project mounted at `/app`,
add:

```yaml
services:
  app:
    environment:
      GIT_SSH_COMMAND: "${KANBAN_GIT_SSH_COMMAND-ssh -i /app/.git/laravel-house/deploy_key -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/app/.git/laravel-house/known_hosts}"
```

Your own git on the host keeps using your own key. To see what goes wrong in the container, run
`vendor/bin/kanban sync` inside it.

For an https remote, or a host that disables deploy keys, set `KANBAN_GIT_TOKEN` in `.env` to a fine-grained token
with read and write access to this repository's contents only. The deployment skill's local compose file hands it to
git in the main stack's container, and only for your remote's host.

<a name="when-two-people-edit-the-same-card"></a>
### When Two People Edit the Same Card

If two people change the same field of a card, the newer change wins. When the field is text, such as the title or the
description, the card's history keeps the older version. Open the card or run `vendor/bin/kanban show` to see it.

The history shows who made each change, such as *Ana* for what Ana did herself and *worker (Ana)* for her agents. The
name comes from `KANBAN_USER`, or from git's `user.name` when it is not set.

If a sync keeps failing, the board page shows a notice, and `status` and `doctor` print the reason.

<a name="kanban-commands"></a>
## Kanban Commands

Every command runs as `vendor/bin/kanban <command>` without booting your app, or as `php artisan kanban:<command>`.
The one exception is `kanban:install`, which runs through Artisan only. Add `--help` to any command for its options.
The [protocol reference](resources/boost/skills/kanban/references/protocol.md) lists every flag and exit code.

**Reading the board**

| Command | Description |
|---|---|
| `status` | What is in progress, blocked and next, and any failing checks. |
| `list` | Cards in `planning`, `ready`, `doing` and `review`, plus blocked cards. |
| `show ID [--plan]` | One card with its criteria, dependencies and history; `--plan` prints only its plan. |
| `next` | The card that would be started next, or with `--planning` planned next. `-v` says why the others wait. |
| `upstream` | Package findings waiting to be filed. |
| `morning` | What merged, what is blocked, the questions, the discovered cards waiting for criteria, the failures on `main` waiting for an area and what the agents spent, since yesterday. |
| `questions` | Every question waiting for you, with its options: open questions on unfinished cards, and provisional decisions until you answer them. |
| `doctor` | Checks the installation. `--fix` repairs it. |
| `validate` | Checks every board file. `--fix` rewrites them. |

**Changing the board**

| Command | Description |
|---|---|
| `new work "Title"` | Adds a card. `--epic=SLUG` puts it in an epic. |
| `epic SLUG "Title"` | Adds or updates an epic: `--goal=` and `--done-when=`. Without a slug, lists the epics. |
| `fold ID --into=ID` | Merges cards into one. |
| `answer ID#N OPTION` | Records your answer to a question, then moves the card on. |
| `set ID key=value` | Changes a card, such as `priority=high`, `epic=passkey-login` or `note="…"`. |
| `move ID STAGE` | Moves a card to another stage, or `--board=BOARD` to another board. |
| `promote ID` | Moves a card from `backlog` to `planning`, or to `ready` when its plan still holds. `--auto` fills `planning` with complete cards, up to 12 by default, and sends ready cards without a plan back to `planning`. |
| `plan ID` | Gives a card in `planning` a plan of your own (`--plan-file=`). A planner hands in its plan the same way. |
| `board BOARD "Title"` | Adds or updates a board. |
| `fold-boards` | Moves an older board onto one work board, with each card's epic taken from where it was. `/implement-kanban` runs it. |

**Working on cards** (usually run by Claude and `kanban run`)

| Command | Description |
|---|---|
| `run` | The routine of running the board: starts cards and agents, and runs the merge queue. Claude runs it for you. |
| `drain` | Makes `run` wrap up: it starts no new card and stops once none is in flight. `drain --off` undoes it. |
| `start ID` | Claims a card and creates its clone and stack: a ready card for its worker, a planning card for its planner. A branch that `stop` kept is reused, with the latest `main` merged in. A refusal names the card ahead on the same area, or the limit it hit. |
| `refresh ID` | Merges the latest `main` into the branch of a card in `doing` or `planning`, in the card's container, once everything in its clone is committed. When the merge changes a lockfile, a docker file or the compose file, it recreates the card's stack. A card in `review` is merged as it is, by the merge queue. |
| `rebuild-branch ID` | Turns a card's branch into one commit with the same files, when a merge of `main` carries changes of its own. |
| `wait [ID]` | Waits until the card's agent has stopped and its plan, report or verdict is on the board. Without an ID, it waits for any card an agent works on. |
| `finish [ID]` | The merge queue's step: merges the card, or this machine's next approved card, into `main`, pushes it and cleans up. `--abort` gives back a merge that no `finish` runs for. Exit code 10 means the card merged, but a step on this machine failed: following `main`, before the merge or after it, or the cleanup; 11 that it waits its turn; 12 that the merger takes over; 13 that the card left the queue. |
| `stop ID --to=STAGE` | Takes a card out of work, ends the agent `kanban run` started for it and cleans up. A branch with commits is kept for the next `start`. A card its planner has planned moves to `ready` this way. |
| `stack ID up\|down\|reload\|logs\|url` | Manages a card's stack, or the merge stack as `stack _merge`. `stack ID exec -- CMD` runs a command in it. |
| `gates` | Runs the quality gates in a card. |
| `sync` | Pulls and pushes the board. |
| `publish` | Pushes the board, and `main` when it holds commits made outside the merge queue. It never merges. |
| `attach` | Checks out the board on this machine. |

<a name="kanban-troubleshooting"></a>
## Kanban Troubleshooting

The [gotchas](resources/boost/skills/kanban/references/gotchas.md) list problems met in real projects, each with its
cause and its fix. The most common ones are:

- **"Agent type not found", or the hooks do not run.** Restart Claude Code after installing or updating.
- **`set` exits with "a locked stage".** The card is in a locked stage: `doing`, `review` or `done`.
  See [Locked Stages](#locked-stages).
- **Several cards are ready but only one starts.** They share an area. `vendor/bin/kanban next -v` says so, and a
  refused `start` names the card ahead.
- **A ready card went back to `planning`.** Its criteria or description changed after it was planned, and its
  planner plans it again.
- **Approved cards never merge.** `finish.check` names no test suite. See [The Merge Queue](#the-merge-queue).
- **Every command says "an older board format".** The board predates version 3: boards inside epic directories.
  Run `/implement-kanban`.
- **Compose fails after about six stacks.** Widen the [Docker address pools](#docker-address-pools).
- **Tests in a worktree hit your main database.** Remove `DB_HOST` and `DB_PORT` from `phpunit.xml`.
- **The board page answers 403 for a host name.** Add that name to `ui.hosts` in `config/kanban.php`.

<a name="updating"></a>
## Updating

How you update depends on how you installed.

<a name="updating-the-plugin"></a>
### Updating the Plugin

Update the plugin with:

```shell
claude plugin update laravel-house@laravel-house
```

Then restart Claude Code. The plugin has no version number, so it follows the default branch: every pushed commit is
an update. You may turn on auto-update under `/plugin`, in Marketplaces.

<a name="updating-the-composer-package"></a>
### Updating the Composer Package

To move to the latest release, require the package with no version. If the project is on the board, let
`doctor --fix` rewrite the agents, the hooks, the `CLAUDE.md` block and the git config. Then refresh the skills:

```shell
composer require --dev petar-spasic/laravel-house
vendor/bin/kanban doctor --fix
php artisan boost:update
```

Skip the `doctor` line in a project without the board. On the board, commit the changes to `main` and run
`vendor/bin/kanban publish`, so your `main` does not diverge from the remote's at the next merge. Then restart Claude
Code. Releases are semver tags.

> [!WARNING]
> While the package is at 0.x, `^0.N` stays on `0.N.x`, so `composer update` alone never reaches a new minor
> version.

> [!WARNING]
> A project that still requires `petar-spasic/laravel-kanban` cannot require this package next to it: the two
> conflict. Stop any running agents, then swap them in one shell call. Leave out `vendor/bin/kanban doctor --fix` when
> the project has no board:
>
> ```shell
> composer remove --dev petar-spasic/laravel-kanban && composer require --dev petar-spasic/laravel-house -W && vendor/bin/kanban doctor --fix && php artisan boost:update
> ```
>
> Then restart Claude Code and run `/implement-kanban`. Its case for that package finishes the move on every clone.

> [!WARNING]
> Every clone that shares a board must run the same version of the package. Commit `composer.lock`. Each other clone
> then runs `composer install` and `vendor/bin/kanban doctor --fix`.

> [!WARNING]
> Version 0.12 merges approved cards through the merge queue, which pushes `main` on every merge. A machine on an
> older version merges around the queue, so stop `kanban run` on every machine before you update any of them. Then:
>
> 1. Answer every open question whose card has a `Steering:` line (a change to `.claude/`, `config/kanban.php`,
>    `.gitattributes` or a hooks directory). The new version merges such a card as it is.
> 2. Run `vendor/bin/kanban publish` on each machine, so no merge stays only on its own `main`.
> 3. On one machine, update the package and run `vendor/bin/kanban doctor --fix`. It writes the merger agent.
> 4. Set `finish.check`, or rewrite it, as your test suite runs in the app container (on your machine with
>    `KANBAN_AGENT_SHELL=host`), without `docker compose exec`. Until you do, nothing merges.
> 5. Commit these changes to `main` and run `vendor/bin/kanban publish` before `kanban run` starts again. The queue
>    merges onto the remote's `main`, so a commit left only on yours makes it diverge. Every other machine then pulls
>    `main`, runs `composer install` and `vendor/bin/kanban doctor --fix`.
>
> A project without worktree stacks merges nothing at all, because the queue runs that suite in a stack of its own.
> Approved cards keep their approval and merge in approval order.

> [!WARNING]
> Version 0.10 renders the house rules on every `composer update`. A house project set up earlier has no
> `config/house.php` and keeps its rules as they are until it [adopts the current core](#adopting-the-current-core)
> once.

> [!WARNING]
> Version 0.9 adds the `planning` stage and its planner agent. Update every clone, run `vendor/bin/kanban doctor --fix`
> to write the planner, and restart Claude Code. The next `kanban promote --auto`, which `kanban run` runs on every pass,
> moves the ready cards that have no plan to `planning`.

> [!WARNING]
> Version 0.7 moves a board onto one work board: each card takes its epic from the directory it was in, and decision
> cards become an archive. Finish or stop every card in progress, update every clone, then run `/implement-kanban`.
> Until then, board commands refuse with `an older board format`.

<a name="adopting-the-current-core"></a>
### Adopting the Current Core

The core is what [every project gets](#what-every-project-gets). When the core changes, a project set up earlier may
adopt it without running setup again.

1. [Update the composer package](#updating-the-composer-package), then restart Claude Code. The project uses its own
   copy of the skills, not the plugin, so this step comes first.
2. Ask Claude to adopt the current house core.

The setup skill then installs Sanctum and Socialite if they are missing. It moves your rule files onto the rendered
house rules, keeping your own rules beside them, and merges the current routes and end-to-end tests into yours. Then
the deployment skill puts Caddy in front of the local stack and removes the earlier local web server.

> [!WARNING]
> Adopting rebuilds the local image. Commit your work before you start.

Two changes are never part of adopting. Each one is a separate decision:

- adding tenancy to an app that already has data;
- moving an existing SvelteKit frontend to server rendering and `/api/auth`. Adopting leaves `frontend/` as it is.

<a name="license"></a>
## License

Laravel House is open-sourced software licensed under the [MIT license](LICENSE).
