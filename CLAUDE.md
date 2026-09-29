# CLAUDE.md — petar-spasic/laravel-house

The owner's house skills for Laravel projects, served two ways from one copy:
- a Claude Code plugin marketplace (`.claude-plugin/`), installed at user scope to start new projects;
- a composer dev package whose skills Laravel Boost copies into a project's `.claude/skills/`.

Consumers read `README.md`. This file is for working **on** the skills.

## Layout

| Path | Role |
|---|---|
| `resources/boost/skills/<name>/` | The only copy of each skill: `SKILL.md`, `references/`, and `templates/` or `scripts/` where a skill ships files. Boost reads this path from every direct, non-first-party dependency listed in `boost.json` `packages` |
| `.claude-plugin/marketplace.json` | The marketplace; its one plugin's `source` is the repository root (`"./"`) |
| `.claude-plugin/plugin.json` | The plugin; `"skills": "./resources/boost/skills/"` makes the same directory its skills |

`CLAUDE.md` files under `templates/` are data for the projects a skill sets up; they do not govern this repository.

## Rules

1. Be concise; comment only what can't be inferred. **No provenance anywhere**: no "was X, now Y", "taken from
   <project>", "previously…". Files state what is; history lives in git.
2. **No project or machine specifics** in a skill: no project names, no real IP addresses (examples use RFC 5737:
   192.0.2.x, 198.51.100.x, 203.0.113.x), no home-directory paths, no concrete host ports, no owner-personal settings
   stated as universal. One placeholder style: `{{app}}` for the project slug, `{{web_port}}`-style for values.
3. **One copy per skill**, under `resources/boost/skills/`. Never a second copy or a symlink for the plugin.
4. **Skill format:** frontmatter `name` + folded `description` ("… Use when … Triggers — …"); `SKILL.md` under 300
   lines; detail in `references/`, one level deep. Improving also means removing: prune what is redundant, stale,
   speculative or one-off whenever a skill is touched.
5. **One owner per topic; point, never copy.** Kanban protocol, install output and gotchas: petar-spasic/laravel-kanban.
   Compose, entrypoint, Vite and phpunit shapes, the Hosting section: `laravel-deployment`. Seeding, conventions, PHP
   minor, ports, the Horizon gate: `laravel-project-setup`.
6. **Boost rewrites what it copies:** a `*.blade.php` file inside a skill is rendered and saved as `.md`, and every
   `.md` file goes through its Markdown formatter. A template that must reach a project as `.blade.php` needs another
   extension here.
7. Dependencies: `laravel/*` and `petar-spasic/*` only; `composer.json` requires `php` alone. No hosted CI and no
   `.github/workflows`: checks run locally before a push. Commits carry no Co-Authored trailer.
8. The projects these skills set up test end to end only. A surprise met while using a skill goes into that skill in
   the same session, as symptom → cause → fix.

## Verify before every push

```bash
claude plugin validate .                                        # passes; the one warning is the omitted version
claude --plugin-dir . plugin details laravel-house              # every skill listed
for f in .claude-plugin/*.json composer.json; do php -r 'json_decode(file_get_contents($argv[1]), flags: JSON_THROW_ON_ERROR);' "$f"; done
wc -l resources/boost/skills/*/SKILL.md                         # each under 300
grep -rniE "pisar|pazarko|supply|fab-portal|192\.168|/home/petar" --exclude-dir=.git . | grep -vE '^(\./)?CLAUDE\.md:'   # empty
bash -n resources/boost/skills/laravel-deployment/templates/docker/*.sh
```

Dry-run the setup installer against a scratch Laravel app for every module combination; it must resolve every block
and placeholder:
`php resources/boost/skills/laravel-project-setup/scripts/install.php <app> --modules=<m,…> --set app=<slug> --dry-run`.

## Release

Tag `vX.Y.Z` for composer. `plugin.json` carries no `version`, so plugin users follow the default branch; adding one
would freeze them on it until the next bump. Consumers: `composer update petar-spasic/laravel-house && php artisan
boost:update`, or `claude plugin update laravel-house@laravel-house`, then restart Claude Code.
