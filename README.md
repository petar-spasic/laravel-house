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
- [The Kanban Board](#the-kanban-board)
- [Exporting Validation Rules](#exporting-validation-rules)
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

There are three skills:

- `laravel-project-setup` starts a project, or brings an older house project up to date.
- `laravel-deployment` runs the project in Docker, locally and in production.
- `implement-kanban` puts the project on the [laravel-kanban](https://github.com/petar-spasic/laravel-kanban) board.

The composer package also ships one Artisan command, [`validation:export`](#exporting-validation-rules).

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
- The **composer package** pins the skills to one project, at the version in its `composer.lock`.

You need Claude Code, PHP with Composer, Node with npm, git, and Docker with Compose v2. To let Claude create the
GitHub repository, you also need the `gh` CLI.

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
into the project's `.claude/skills/` directory. These copies have no prefix, for example `/implement-kanban`.

Laravel registers the `validation:export` command on its own. There is nothing to configure.

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
board. If the command is not found, run `/reload-skills` first.

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
- `ProductionSeeder` creates what production needs: the operator account and the admins.
- `DatabaseSeeder` picks between them.

Every seeder is safe to run twice.

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

- The root `CLAUDE.md` holds the stack and the rules for the whole project.
- Each layer directory, such as `app/Models` or `routes`, has its own `CLAUDE.md`. Claude reads it when it works
  there.
- A change to the folder layout, the auth model or the routes updates the matching rule file in the same commit.

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
  the repository in its own directory.

> [!WARNING]
> The local stack listens on your LAN by default, and the board at `/kanban` has no login. Set `WEB_BIND=127.0.0.1`
> in `.env` to keep the stack on your machine, or set `KANBAN_UI_TOKEN` to protect the board.

<a name="the-production-image"></a>
### The Production Image

- FrankenPHP runs Octane as a non-root user. With the SvelteKit app, a Node process renders the pages behind the same
  Caddy.
- TLS ends at a reverse proxy on your server. The image publishes its port on `127.0.0.1` only.
- In `.env.prod`, set `OCTANE_WORKERS` to a number that fits your server's memory.
- In `.env.prod`, set `TRUSTED_PROXIES` to your reverse proxy's address, as the container sees it. That is usually
  the Docker network's gateway. With the SvelteKit app, also add `127.0.0.1`.

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

<a name="the-kanban-board"></a>
## The Kanban Board

[laravel-kanban](https://github.com/petar-spasic/laravel-kanban) is a kanban board kept in your git repository and
shared through `origin`. Claude agents take its cards and work on them. The `/implement-kanban` skill puts your project
on it:

- It installs the package.
- It records every setup decision as a card, and every open question as a proposed card.
- It prepares one Docker stack per worktree.
- It runs a first card through the agent loop.

It also upgrades a project that already has the board.

> [!NOTE]
> Only you can start this skill, by typing `/implement-kanban`. Claude never starts it on its own.

The [laravel-kanban README](https://github.com/petar-spasic/laravel-kanban) covers the board itself.

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
- When a rule cannot be translated, the command fails and names the class, the field and the rule.
- Commit the generated files. `php artisan validation:export --check` fails when they no longer match the requests.
  The frontend rules make `npm run check` run it first.

> [!NOTE]
> The command ships in this dev package. In production the attribute does nothing, so an install without dev
> dependencies validates as usual.

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

To move to the latest release, require the package with no version, then refresh the skills:

```shell
composer require --dev petar-spasic/laravel-house
php artisan boost:update
```

Then restart Claude Code. Releases are semver tags.

> [!WARNING]
> While the package is at 0.x, `^0.N` stays on `0.N.x`, so `composer update` alone never reaches a new minor
> version.

<a name="adopting-the-current-core"></a>
### Adopting the Current Core

The core is what [every project gets](#what-every-project-gets). When the core changes, a project set up earlier may
adopt it without running setup again.

1. Run `composer require --dev petar-spasic/laravel-house` and `php artisan boost:update`, then restart Claude Code.
   The project uses its own copy of the skills, not the plugin, so this step comes first.
2. Ask Claude to adopt the current house core.

The setup skill then installs Sanctum and Socialite if they are missing. It merges the current rule files, routes and
end-to-end tests into yours, and keeps your own text. Then the deployment skill puts Caddy in front of the local stack
and removes the earlier local web server.

> [!WARNING]
> Adopting rebuilds the local image. Commit your work before you start.

Two changes are never part of adopting. Each one is a separate decision:

- adding tenancy to an app that already has data;
- moving an existing SvelteKit frontend to server rendering and `/api/auth`. Adopting leaves `frontend/` as it is.

<a name="license"></a>
## License

Laravel House is open-sourced software licensed under the [MIT license](LICENSE).
