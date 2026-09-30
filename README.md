# laravel-house

House skills for Laravel projects, for Claude Code:

| Skill | Does |
|---|---|
| `laravel-project-setup` | Starts a project on the house rules: the `CLAUDE.md` rule system, Stripe-style ids, E2E-only tests, the seeding standard, the stack modules |
| `laravel-deployment` | Dockerizes it: a local image (nginx + php-fpm + Xdebug + Vite) and a production image (FrankenPHP + Octane), with Horizon and the scheduler under supervisor and Postgres and Redis sidecars |
| `implement-kanban` | Brings it onto [petar-spasic/laravel-kanban](https://github.com/petar-spasic/laravel-kanban): the board shared through `origin` (team sync), worktree stacks, the agent loop; also upgrades a project that has it installed. Only the owner starts it, by typing the command |

One copy of each skill lives in `resources/boost/skills/` and reaches Claude Code two ways.

## As a plugin — new projects

A new project has no composer package yet, so the skills come from the plugin, installed once per machine:

```bash
claude plugin marketplace add petar-spasic/laravel-house
claude plugin install laravel-house@laravel-house --scope user
```

Plugin skills are namespaced: `/laravel-house:laravel-project-setup`, `/laravel-house:implement-kanban`.

## As a composer package — per project

The package pins the skills to the project; Laravel Boost 2.x installs them:

```bash
composer require --dev petar-spasic/laravel-house
```

Add `"petar-spasic/laravel-house"` to `packages` in `boost.json` and run `php artisan boost:update`: Boost copies each
skill into `.claude/skills/<name>/` (`/implement-kanban`, …).

With both installed, a project sees every skill twice. Its own copy is the pinned one, so turn the plugin off there:
`claude plugin disable laravel-house@laravel-house --scope project`.

## Updating

- Plugin: `claude plugin update laravel-house@laravel-house`, then restart Claude Code. The plugin carries no
  `version`, so it follows the default branch: every pushed commit is an update. `/plugin` → Marketplaces can turn on
  auto-update.
- Package: `composer update petar-spasic/laravel-house && php artisan boost:update`, then restart Claude Code. Releases
  are semver tags.

## License

MIT
