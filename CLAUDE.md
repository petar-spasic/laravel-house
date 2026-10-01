# CLAUDE.md — petar-spasic/laravel-house

The owner's house skills for Laravel projects, served two ways from one copy:
- a Claude Code plugin marketplace (`.claude-plugin/`), installed at user scope to start new projects;
- a composer dev package whose skills Laravel Boost copies into a project's `.claude/skills/`.

The composer package also ships a small PHP part, `src/`, to every project that requires it.

Consumers read `README.md`. This file is for working **on** the skills.

## Layout

| Path | Role |
|---|---|
| `resources/boost/skills/<name>/` | The only copy of each skill: `SKILL.md`, `references/`, and `templates/` or `scripts/` where a skill ships files. Boost reads this path from every direct, non-first-party dependency listed in `boost.json` `packages` |
| `.claude-plugin/marketplace.json` | The marketplace; its one plugin's `source` is the repository root (`"./"`) |
| `.claude-plugin/plugin.json` | The plugin; `"skills": "./resources/boost/skills/"` makes the same directory its skills |
| `src/` | The `validation:export` command and its service provider. It uses only classes the host Laravel app provides |
| `composer.json` | The package. Requires `php` alone; autoloads `src/` and registers the provider (rule 7) |
| `README.md` | The one consumer doc (rule 10) |

Template rule files are stored as `CLAUDE.md.stub` (the installer drops `.stub`): a real `CLAUDE.md` under `templates/`
would load here as live instructions. Never rename one back.

## Rules

1. Be concise; comment only what can't be inferred. **No provenance anywhere**: no "was X, now Y", "taken from
   <project>", "previously…". Files state what is; history lives in git.
2. **No project or machine specifics** in a skill: no project names, no real IP addresses (examples use RFC 5737:
   192.0.2.x, 198.51.100.x, 203.0.113.x), no home-directory paths, no concrete host ports, no owner-personal settings
   stated as universal. One placeholder style: `{{app}}` for the project slug, `{{web_port}}`-style for values.
3. **One copy per skill**, under `resources/boost/skills/`. Never a second copy or a symlink for the plugin.
4. **Skill format:** frontmatter `name` + folded `description` ("… Use when … Triggers — …"), at most 1024 characters;
   `SKILL.md` under 300 lines; detail in `references/`, one level deep. Improving also means removing: prune what is
   redundant, stale, speculative or one-off whenever a skill is touched.
5. **One owner per topic; point, never copy.**
   - petar-spasic/laravel-kanban: the kanban protocol, install output and gotchas.
   - `laravel-deployment`: the compose, entrypoint, Caddy, Vite and phpunit shapes; the Hosting section; the spa path
     split (`references/spa.md`); tenancy's database roles (`references/tenancy.md`).
   - `laravel-project-setup`: seeding, conventions, the PHP minor, ports, the Horizon gate; which modules combine (its
     SKILL.md Modules table and `install.php`); the core-auth and tenancy rules (the `CLAUDE.md` stubs); the spa
     frontend rules (`templates/modules/spa/frontend/CLAUDE.md.stub`).
   - `src/`: what `validation:export` does.
   - `README.md`: what a consumer reads. It describes and points; it never restates rule text.
6. **Boost rewrites what it copies:** a `*.blade.php` file inside a skill is rendered and saved as `.md`, and every
   `.md` file goes through its Markdown formatter. A template that must reach a project as `.blade.php` needs another
   extension here.
7. Dependencies: `laravel/*` and `petar-spasic/*` only. `composer.json` requires `php` alone; `src/` is autoloaded
   (`PetarSpasic\LaravelHouse\` → `src/`) and its provider is discovered through `extra.laravel.providers`. No hosted
   CI and no `.github/workflows`: checks run locally before a push. Commits carry no Co-Authored trailer.
8. The projects these skills set up test end to end only. A surprise met while using a skill goes into that skill in
   the same session, as symptom → cause → fix.
9. **Rules, not code.**
   - Templates ship the infrastructure (Dockerfiles, compose, Caddyfiles, entrypoints, healthcheck) and the files
     under `templates/` today: the backend files, the htmx and islands boot files, `config/boost.php` and the Boost
     overrides, the snippets, the E2E tests. They stay.
   - Every module's frontend, Socialite and tenancy ship as binding rules in the `CLAUDE.md` stubs.
   - Module text sits in `<!-- if:m -->` blocks. A deployment module's additions sit in its reference file, so an app
     without the module carries none of it.
   - New shipped code needs the owner's OK.
10. **`README.md` follows the Laravel docs style.**
    - One H1, then the TOC: H2 entries at column 0, H3 entries indented four spaces.
    - `<a name="…"></a>` on the line directly above each H2 and H3.
    - Introduction first. Only `> [!NOTE]` and `> [!WARNING]`.
    - Every fence is tagged and introduced by a sentence ending in a colon.
    - Second person, short sentences, one idea each. Explain a term once, or leave it out.
    - A change to what a consumer sees updates it in the same commit.

## Verify before every push

Each line passes or prints nothing:

```bash
# Package and manifests
claude plugin validate .                                        # passes; the one warning is the omitted version
claude --plugin-dir . plugin details laravel-house              # lists all three skills
composer validate --no-check-publish
for f in .claude-plugin/*.json composer.json; do php -r 'json_decode(file_get_contents($argv[1]), flags: JSON_THROW_ON_ERROR);' "$f"; done
php -r '$c = json_decode(file_get_contents("composer.json"), true); foreach ($c["extra"]["laravel"]["providers"] as $p) { $f = "src/".str_replace(["PetarSpasic\\LaravelHouse\\", "\\"], ["", "/"], $p).".php"; is_file($f) || print("✗ $p\n"); }'
find src -name '*.php' -exec php -l {} \; | grep -v '^No syntax errors'
php -l resources/boost/skills/laravel-project-setup/scripts/install.php | grep -v '^No syntax errors'

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
grep -rniE "pisar|pazarko|supply|fab-portal|192\.168|/home/petar" --exclude-dir=.git . | grep -vE '^(\./)?CLAUDE\.md:'   # no project or machine specifics
grep -rniE 'nginx|HMR_CLIENT_PORT|xfwd' resources README.md     # Caddy is the only web server
grep -rnE 'ssr-dev|DB_OWNER_USERNAME|VITE_REVERB|(^|[^i])/broadcasting/auth([^/]|$)|E2E_NODE_PORT|from .cn.' resources README.md   # stale names
grep -rnE 'descriptor|dist/validation|zodFromDescriptor|FormController' resources src README.md   # validation:export is the one bridge to the frontend
grep -rn 'viewPrefix' resources/boost/skills/laravel-project-setup/templates/snippets   # htmx Fortify views stay off until the project's pages exist
grep -rnE '\{\{(web_port|domain)\}\}' resources/boost/skills/laravel-project-setup/templates   # deployment's placeholders
grep -rliE 'tenan(t|cy)|pgsql_owner|DB_OWNER_' resources/boost/skills/laravel-deployment/templates --exclude=roles.sql   # tenancy lives in references/tenancy.md

# Templates
for f in resources/boost/skills/laravel-deployment/templates/docker/*.sh; do bash -n "$f" || echo "✗ $f"; done
c=$(mktemp -d); for f in resources/boost/skills/laravel-deployment/templates/docker/Caddyfile.local*; do sed -e 's/{{app}}/acme/g' -e 's/{{[a-z_]*}}/8000/g' "$f" > "$c/$(basename "$f")"; docker run --rm -v "$c:/c:ro" caddy:2 caddy adapt --config "/c/$(basename "$f")" --adapter caddyfile >/dev/null 2>&1 || echo "✗ $f"; done; rm -rf "$c"

# README
grep -oE '\]\(#[a-z0-9-]+\)' README.md | sed -E 's/.*#(.*)\)/\1/' | sort -u | while read -r a; do grep -q "<a name=\"$a\"></a>" README.md || echo "✗ anchor $a"; done
awk 'prev ~ /^<a name=/ && !/^##+ / { print "✗ " prev } /^##+ / && prev !~ /^<a name=/ { print "✗ no anchor: " $0 } { prev = $0 }' README.md
```

After renaming a skill section, run `grep -rn 'laravel-deployment\|laravel-project-setup\|references/\|Many stacks\|Procedure\|Verify' resources README.md`
and fix every hit. implement-kanban's citations wrap across lines: join them (`tr '\n' ' '`) before matching.

Then run the installer for all 16 module combinations into scratch directories. Each run must exit 0 and leave only
`what_we_are_building` and `hosting`; write lint-clean PHP with no block markers, `.gitkeep` and `AcceptJson.php`;
carry `## Tenancy` exactly when tenancy is on; write exactly `frontend/CLAUDE.md` with spa and no spa text without it.
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
  [ -f "$d/database/data/.gitkeep" ] && [ -f "$d/app/Http/Middleware/AcceptJson.php" ] || echo "✗ shipped files [$m]"
  if [ -n "$tn" ]; then grep -q '^## Tenancy' "$d/CLAUDE.md" || echo "✗ tenancy rules missing [$m]"
  else grep -rliE 'tenan(t|cy)' "$d" && echo "✗ tenancy text [$m]"; fi
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

A new placeholder joins `sets` here and setup's step 4.

## Release

Tag `vX.Y.Z` for composer. `plugin.json` carries no `version`, so plugin users follow the default branch; adding one
would freeze them on it until the next bump. Consumers: `composer update petar-spasic/laravel-house && php artisan
boost:update`, or `claude plugin update laravel-house@laravel-house`, then restart Claude Code.

A release that changes the core is a new minor. A 0.x caret never crosses a minor, so consumers cross it with
`composer require --dev petar-spasic/laravel-house` and no constraint (README, Updating).

Before tagging a release that touches `src/` or `composer.json`, smoke-install it into a scratch Laravel app outside
the repo, with `house` set to this repository's path:

```bash
composer config repositories.house path "$house"
composer require --dev petar-spasic/laravel-house:@dev
php artisan list | grep validation:export                       # listed
```
