<?php

declare(strict_types=1);

/*
 * Copies <templates>/core and the chosen <templates>/modules/<m> into a Laravel repo. <templates> is this skill's
 * templates/ unless --templates names another tree of the same shape (laravel-deployment's).
 * Resolves `<!-- if:m -->` / `<!-- unless:m -->` … `<!-- endif -->` and `# if:m` / `# unless:m` … `# endif` blocks
 * (own lines, nestable, either style in any file) and `{{key}}` placeholders given with --set.
 * Never overwrites an existing file without --force. A file that resolves to nothing is not written; `*.sh` files are
 * written executable. A `.stub` suffix is dropped on write: Boost renders any `*.blade.php` inside a skill it copies.
 * --render-to=<dir> writes the resolved templates, plus <templates>/snippets/ under <dir>/snippets/, into <dir>
 * instead of the repo: for merging snippets and for diffing an existing project against the templates.
 * Each placeholder no --set filled is reported as `placeholders left in <file>: <keys>`.
 * --fresh first makes the fixed edits to a fresh skeleton (a repo with no commit yet): it deletes what the templates
 * replace or the modules drop, points `.env` and the config defaults at Postgres and Redis, writes `boost.json`, and
 * wires composer, npm and `.gitignore`. A known line it cannot find is reported as `merge by hand: …`.
 * The modules and the house values (`app` and the versions) are recorded in `config/house.php`.
 *
 * --update renders the house files again from the repo's `config/house.php` and writes only what the house owns: the
 * span between `<!-- house:begin … -->` and `<!-- house:end -->` in a file whose template has one, and every file under
 * `.ai/` whole. It creates a house file the repo lacks, leaves a path listed in `overrides` alone, and names a file
 * that lost its markers instead of touching it, and a house file of a module that is off. It prints one line per
 * change, nothing when the repo is in sync.
 * --check does the same without writing and exits 1 when anything would change.
 * `config/house.php` is only ever created, never overwritten, --force included: it holds the project's overrides and
 * the `auth-pages` state.
 * --adopt moves an existing project onto the rendered rules: it writes `config/house.php` from --modules and the --set
 * values, and puts `house:update` right before `boost:update` in composer's `post-update-cmd`. Nothing else.
 *
 * php install.php <repo> --modules=htmx,islands,tenancy --set app=acme [--set key=value …] [--force] [--dry-run]
 *   [--render-to=<dir>] [--templates=<dir>] [--fresh]
 * php install.php <repo> --update [--check]
 * php install.php <repo> --adopt --modules=… --set app=… --set laravel_version=… … [--dry-run]
 */

use PetarSpasic\LaravelHouse\Setup\HouseConfig;

require_once __DIR__.'/HouseConfig.php';

const HOUSE_BLOCK = '/^<!-- house:begin\b[^\n]*-->\r?\n.*?^<!-- house:end -->(?=\r?$)/ms';

$repo = null;
$modules = [];
$vars = [];
$force = false;
$dryRun = false;
$renderTo = null;
$fresh = false;
$update = false;
$check = false;
$adopt = false;
$replaced = [];
$templates = dirname(__DIR__).'/templates';
$args = array_slice($argv, 1);

for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if (str_starts_with($arg, '--modules=')) {
        $modules = array_values(array_filter(explode(',', substr($arg, 10))));
    } elseif ($arg === '--set') {
        [$key, $value] = explode('=', $args[++$i] ?? '', 2) + [1 => null];
        $value === null ? fail('--set needs key=value') : $vars[$key] = $value;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--render-to=')) {
        $renderTo = rtrim(substr($arg, 12), '/') ?: fail('--render-to needs a directory');
    } elseif ($arg === '--fresh') {
        $fresh = true;
    } elseif ($arg === '--update') {
        $update = true;
    } elseif ($arg === '--check') {
        $check = true;
    } elseif ($arg === '--adopt') {
        $adopt = true;
    } elseif (str_starts_with($arg, '--templates=')) {
        $templates = rtrim(substr($arg, 12), '/');
    } else {
        $repo = $arg;
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "install: {$message}\n");
    exit(1);
}

if ($repo === null || ! is_file("{$repo}/artisan")) {
    fail('first argument must be a Laravel repo (no artisan found)');
}
if (! is_dir("{$templates}/core")) {
    fail("no core/ in the templates directory {$templates}");
}
// setup's own templates: the only ones that render config/house.php and the house files
$setup = realpath($templates) === realpath(dirname(__DIR__).'/templates');
if ($check && ! $update) {
    fail('--check goes with --update');
}
if ($adopt && (! $setup || $update || $fresh || $force || $renderTo !== null)) {
    fail('--adopt takes only --modules, --set and --dry-run');
}
if ($update) {
    ($modules === [] && $vars === [] && ! $force && ! $dryRun && $renderTo === null && ! $fresh && $setup)
        || fail('--update takes only --check: the modules and values come from config/house.php');
    try {
        $config = HouseConfig::read($repo);
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage());
    }
    [$vars, $modules, $overrides] = [$config->vars, $config->modules, $config->overrides];
}
($why = HouseConfig::refusal($modules)) === null || fail($why);
// a recorded project renders from config/house.php: other modules or values here would be undone by the next update
$recorded = null;
if ($setup && ! $update && $renderTo === null && is_file("{$repo}/config/house.php")) {
    try {
        $recorded = HouseConfig::read($repo);
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage());
    }
}
if (in_array('auth-pages', $modules, true) && ! $update && ! $adopt && $renderTo === null && ! in_array('auth-pages', $recorded?->modules ?? [], true)) {
    fail('auth-pages is the state of built auth pages, never set at setup: the commit that builds the last page adds it (resources/CLAUDE.md, Auth pages)');
}
if (! preg_match('/^[a-z][a-z0-9]*$/', $vars['app'] ?? '')) {
    fail('--set app=<slug> is required: lowercase letters and digits (it names the test database, the config file and the dev accounts\' email domain)');
}

if ($setup && ! $update && ($missing = array_diff(HouseConfig::VARS, array_keys($vars))) !== []) {
    fail('--set '.implode(', ', array_map(fn ($k) => "{$k}=…", $missing)).' is required: config/house.php records it for the house rules');
}
if ($recorded !== null) {
    [$given, $kept, $values, $keptValues] = [$modules, $recorded->modules, array_intersect_key($vars, $recorded->vars), $recorded->vars];
    sort($given);
    sort($kept);
    ksort($values);
    ksort($keptValues);
    ($given === $kept && $values === $keptValues)
        || fail('config/house.php records other modules or values: change them there and run `php artisan house:update`');
}

if ($fresh) {
    $renderTo === null || fail('--fresh edits the repo; it does not combine with --render-to');
    foreach (['app_name', 'web_port', 'db_port', 'redis_port'] as $key) {
        isset($vars[$key]) || fail("--fresh needs --set {$key}=…");
    }
    $commits = shell_exec('git -C '.escapeshellarg($repo).' rev-parse --verify -q HEAD 2>/dev/null');
    trim((string) $commits) === '' || fail('--fresh is for a fresh skeleton, and this repo has commits: make these edits by hand (references/adopt.md)');
    $replaced = fresh($repo, $modules, $vars, $dryRun);
}

/**
 * $text, a JSON file, changed by $change and written back in its own indentation, tabs included; null when it is not
 * an object. Objects stay objects, so an empty `{}` is written as `{}`.
 */
function jsonEdit(string $text, callable $change): ?string
{
    $data = json_decode($text);
    if (! $data instanceof stdClass) {
        return null;
    }
    $encoded = json_encode($change($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    $indent = preg_match('/^([ \t]+)"/m', $text, $m) ? $m[1] : '    ';

    return preg_replace_callback('/^(?: {4})+/m', fn ($m) => str_repeat($indent, strlen($m[0]) / 4), $encoded);
}

/**
 * composer.json with `house:update` right before `boost:update`, both last in `post-update-cmd`: Boost composes its
 * block from the `.ai/` files house:update writes. A `house:update` or `boost:update` the project wrote, flags and all,
 * is kept and moved.
 */
function houseScripts(stdClass $composer): stdClass
{
    // `"scripts": []` decodes to an array
    $composer->scripts = (object) (array) ($composer->scripts ?? []);
    $artisan = fn (string $command) => fn (mixed $cmd) => is_string($cmd) && preg_match('/\bartisan\s+'.$command.'\b/', $cmd) === 1;
    $commands = (array) ($composer->scripts->{'post-update-cmd'} ?? []);
    $boost = array_values(array_filter($commands, $artisan('boost:update'))) ?: ['@php artisan boost:update --ansi'];
    $house = array_values(array_filter($commands, $artisan('house:update'))) ?: ['@php artisan house:update --ansi'];
    $other = array_filter($commands, fn ($cmd) => ! $artisan('boost:update')($cmd) && ! $artisan('house:update')($cmd));
    $composer->scripts->{'post-update-cmd'} = [...array_values($other), ...$house, ...$boost];

    return $composer;
}

/**
 * Every fixed edit of SKILL.md steps 3 and 6 to a fresh skeleton.
 *
 * @return list<string> the files it deleted (on a dry run, would delete)
 */
function fresh(string $repo, array $modules, array $vars, bool $dryRun): array
{
    $on = fn (string $m) => in_array($m, $modules, true);
    $say = fn (string $line) => print(($dryRun ? 'would: ' : 'fresh: ').$line."\n");
    $edit = function (string $file, callable $change) use ($repo, $dryRun, $say): void {
        $path = "{$repo}/{$file}";
        if (! is_file($path)) {
            echo "merge by hand: {$file} is missing\n";

            return;
        }
        $before = (string) file_get_contents($path);
        $after = $change($before);
        if ($after === null) {
            return;
        }
        if ($after !== $before) {
            $dryRun || file_put_contents($path, $after);
            $say("edited {$file}");
        }
    };
    $patch = function (string $file, array $replacements) use ($edit): void {
        $edit($file, function (string $text) use ($file, $replacements) {
            foreach ($replacements as $pattern => $replacement) {
                $text = preg_replace($pattern, $replacement, $text, -1, $count);
                $count > 0 || print("merge by hand: {$file} ({$replacement})\n");
            }

            return $text;
        });
    };

    $delete = ['tests/Unit', 'tests/Feature', 'database/database.sqlite', 'AGENTS.md', '.agents', 'CLAUDE.md',
        'database/seeders/DatabaseSeeder.php', 'app/Providers/HorizonServiceProvider.php'];
    if ($on('htmx')) {
        $delete[] = 'resources/js/app.js';
    }
    if ($on('spa')) {
        array_push($delete, 'package.json', 'package-lock.json', 'vite.config.js', 'resources/js', 'resources/css',
            'resources/views/welcome.blade.php', 'public/favicon.ico', 'public/robots.txt');
    }
    $deleted = [];
    foreach ($delete as $relative) {
        if (file_exists("{$repo}/{$relative}")) {
            $dryRun || remove("{$repo}/{$relative}");
            $say("deleted {$relative}");
            $deleted[] = $relative;
        }
    }

    $app = $vars['app'];
    $quote = fn (string $v) => preg_match('/[\s#"\'$]/', $v) ? '"'.addcslashes($v, '"\\$').'"' : $v;
    $set = ['APP_NAME' => $quote($vars['app_name']), 'APP_URL' => "http://localhost:{$vars['web_port']}", 'DB_HOST' => '127.0.0.1',
        'DB_PORT' => $vars['db_port'], 'DB_DATABASE' => $app, 'DB_USERNAME' => $app, 'DB_PASSWORD' => $app, 'REDIS_PORT' => $vars['redis_port']];
    foreach (['.env' => '', '.env.example' => '# '] as $file => $comment) {
        $edit($file, function (string $text) use ($set, $comment, $app) {
            $text = preg_replace('/^#?[ \t]*(DB_CONNECTION|SESSION_DRIVER|QUEUE_CONNECTION|CACHE_STORE)=.*\n/m', '', $text);
            foreach ($set + ['ADMIN_EMAILS' => "admin@{$app}.test"] as $key => $value) {
                $line = ($key === 'ADMIN_EMAILS' ? $comment : '')."{$key}={$value}";
                $text = preg_match("/^#?[ \t]*{$key}=.*$/m", $text)
                    ? preg_replace("/^#?[ \t]*{$key}=.*$/m", $line, $text, 1)
                    : rtrim($text, "\n")."\n{$line}\n";
            }

            return preg_replace("/\n{3,}/", "\n\n", $text);
        });
    }

    $patch('config/database.php', ["/env\('DB_CONNECTION', '[a-z]+'\)/" => "env('DB_CONNECTION', 'pgsql')"]);
    $patch('config/queue.php', ["/env\('QUEUE_CONNECTION', '[a-z]+'\)/" => "env('QUEUE_CONNECTION', 'redis')",
        "/env\('DB_CONNECTION', '[a-z]+'\)/" => "env('DB_CONNECTION', 'pgsql')"]);
    $patch('config/cache.php', ["/env\('CACHE_STORE', '[a-z]+'\)/" => "env('CACHE_STORE', 'redis')"]);
    $patch('config/session.php', ["/env\('SESSION_DRIVER', '[a-z]+'\)/" => "env('SESSION_DRIVER', 'redis')"]);
    if ($on('htmx')) {
        $patch('vite.config.js', ['#resources/js/app\.js#' => 'resources/js/app.ts']);
        $patch('resources/views/welcome.blade.php', ['#resources/js/app\.js#' => 'resources/js/app.ts']);
    }
    if ($on('spa')) {
        $patch('routes/web.php', ["/^Route::get\('\/', function \(\) \{\s*return view\('welcome'\);\s*\}\);\n?/m" => '']);
    }

    if (! file_exists("{$repo}/boost.json")) {
        $dryRun || file_put_contents("{$repo}/boost.json", json_encode(['agents' => ['claude_code'], 'cloud' => false,
            'packages' => ['petar-spasic/laravel-house']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $say('wrote boost.json');
    }
    $json = fn (string $file, callable $change) => $edit($file, fn (string $text) => jsonEdit($text, $change));
    $json('composer.json', function (stdClass $c) use ($on) {
        $c = houseScripts($c);
        if ($on('spa')) {
            unset($c->scripts->dev);
            foreach ($c->scripts as $name => $commands) {
                if (is_array($commands)) {
                    $c->scripts->{$name} = array_values(array_filter($commands, fn ($cmd) => ! is_string($cmd) || ! str_contains($cmd, 'npm ')));
                }
            }
        }

        return $c;
    });
    if ($on('htmx')) {
        $json('package.json', function (stdClass $p) use ($on) {
            // `"scripts": []` decodes to an array
            $p->scripts = (object) (array) ($p->scripts ?? []);
            $p->scripts->check = $on('islands') ? 'svelte-check --tsconfig ./tsconfig.json' : 'tsc';

            return $p;
        });
    }

    $ignore = (string) @file_get_contents("{$repo}/.gitignore");
    $missing = [];
    foreach (['/.claude/settings.local.json', '.env.prod', '/frankenphp', '/public/frankenphp-worker.php'] as $line) {
        $ignored = in_array($line, array_map('trim', explode("\n", $ignore)), true)
            || ($line[0] === '/' && trim((string) shell_exec('git -C '.escapeshellarg($repo).' check-ignore '.escapeshellarg(substr($line, 1)).' 2>/dev/null')) !== '');
        $ignored || $missing[] = $line;
    }
    if ($missing !== []) {
        $dryRun || file_put_contents("{$repo}/.gitignore", rtrim($ignore, "\n").($ignore === '' ? '' : "\n").implode("\n", $missing)."\n");
        $say('.gitignore += '.implode(' ', $missing));
    }

    return $deleted;
}

function remove(string $path): void
{
    if (is_dir($path) && ! is_link($path)) {
        foreach (scandir($path) as $entry) {
            in_array($entry, ['.', '..'], true) || remove("{$path}/{$entry}");
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

function resolve(string $text, array $modules, string $file): string
{
    $kept = [];
    $stack = [];
    foreach (explode("\n", $text) as $n => $line) {
        if (preg_match('/^\s*(?:<!-- (if|unless):([a-z-]+) -->|# (if|unless):([a-z-]+))\s*$/', $line, $m)) {
            [$kind, $module] = $m[1] !== '' ? [$m[1], $m[2]] : [$m[3], $m[4]];
            in_array($module, HouseConfig::MODULES, true) || fail("{$file}:".($n + 1)." unknown module {$module}");
            $stack[] = ($kind === 'if') === in_array($module, $modules, true);

            continue;
        }
        if (preg_match('/^\s*(?:<!-- endif -->|# endif)\s*$/', $line)) {
            array_pop($stack) ?? fail("{$file}:".($n + 1).' stray endif');

            continue;
        }
        if (preg_match('/<!-- (?:if:|unless:|endif)|^\s*# (?:if:|unless:|endif\b)/', $line)) {
            fail("{$file}:".($n + 1).' a block marker must be alone on its line');
        }
        if (! in_array(false, $stack, true)) {
            $kept[] = $line;
        }
    }
    $stack === [] || fail("{$file}: unclosed block");

    return preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept));
}

/** `config/house.php`: the modules and the values --update renders the house files from. */
function houseConfig(array $modules, array $vars): string
{
    $lines = [];
    foreach (HouseConfig::VARS as $key) {
        isset($vars[$key]) && $lines[] = "    '{$key}' => ".var_export($vars[$key], true).',';
    }
    $list = implode(', ', array_map(fn (string $m) => var_export($m, true), $modules));

    return "<?php\n\n// The house rules this project follows: `php artisan house:update` (run by `composer update`) renders them from\n"
        ."// these values. Plain values only: the update reads this file without booting the app.\nreturn [\n"
        .implode("\n", $lines)."\n    'modules' => [{$list}],\n"
        ."    // House files this project keeps as its own, by path from the repo root: the update leaves them alone.\n"
        ."    'overrides' => [],\n];\n";
}

/**
 * The files of a template directory, by the path they are written to.
 *
 * @return array<string, string> relative target path => template file
 */
function templateFiles(string $source, string $prefix = ''): array
{
    if (! is_dir($source)) {
        return [];
    }
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[$prefix.preg_replace('/\.stub$/', '', substr($file->getPathname(), strlen($source) + 1))] = $file->getPathname();
    }

    return $files;
}

if ($adopt) {
    $say = fn (string $line) => print(($dryRun ? 'would: ' : 'adopt: ').$line."\n");
    // composer.json first: a file it cannot wire leaves the project as it was
    $composer = (string) @file_get_contents("{$repo}/composer.json");
    $wired = jsonEdit($composer, 'houseScripts') ?? fail('composer.json is not a JSON object');
    if (file_exists("{$repo}/config/house.php")) {
        $say('config/house.php exists: kept');
    } else {
        $dryRun || is_dir("{$repo}/config") || mkdir("{$repo}/config", 0775, true);
        $dryRun || file_put_contents("{$repo}/config/house.php", houseConfig($modules, $vars));
        $say('wrote config/house.php');
    }
    if ($wired !== $composer) {
        $dryRun || file_put_contents("{$repo}/composer.json", $wired);
        $say('composer.json post-update-cmd ends with house:update, then boost:update');
    }
    exit(0);
}

$base = $renderTo ?? $repo;
$rendered = [];
$left = [];
$files = array_merge(templateFiles("{$templates}/core"), ...array_map(fn ($m) => templateFiles("{$templates}/modules/{$m}"), $modules));
if ($renderTo !== null) {
    $files += templateFiles("{$templates}/snippets", 'snippets/');
}
foreach ($files as $relative => $path) {
    $raw = file_get_contents($path);
    $text = resolve($raw, $modules, $relative);
    if (trim($text) === '' && trim($raw) !== '') {
        continue;
    }
    $text = preg_replace_callback('/\{\{([a-z_]+)\}\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $text);
    if (preg_match_all('/\{\{([a-z_]+)\}\}/', $text, $m)) {
        $left[$relative] = array_values(array_unique($m[1]));
    }
    $rendered[$relative] = $text;
}
if (! $update && $setup) {
    $rendered['config/house.php'] = houseConfig($modules, $vars);
}
ksort($rendered);

if ($update) {
    // the files the update writes: a span's template, or one under .ai/. The root CLAUDE.md is not one: Boost writes its
    // rules, and a root topic is overridden by .ai/guidelines/petar-spasic/laravel-house/<topic>.blade.php
    $managed = array_filter(array_merge(templateFiles("{$templates}/core"), ...array_map(fn ($m) => templateFiles("{$templates}/modules/{$m}"), HouseConfig::MODULES)),
        fn (string $path, string $relative) => str_starts_with($relative, '.ai/') || str_contains((string) file_get_contents($path), '<!-- house:begin'), ARRAY_FILTER_USE_BOTH);
    ($unknown = array_diff($overrides, array_keys($managed))) === []
        || fail('config/house.php overrides names no file house:update writes: '.implode(', ', $unknown).' (a root rule is overridden by .ai/guidelines/petar-spasic/laravel-house/<topic>.blade.php)');
    $lines = [];
    foreach ($rendered as $relative => $text) {
        $whole = str_starts_with($relative, '.ai/');
        if (! $whole && preg_match(HOUSE_BLOCK, $text, $block) !== 1 || in_array($relative, $overrides, true)) {
            continue;
        }
        isset($left[$relative]) && $lines[] = "placeholders left in {$relative}: ".implode(', ', $left[$relative]).' (config/house.php)';
        $target = "{$repo}/{$relative}";
        $current = is_file($target) ? (string) file_get_contents($target) : null;
        // a checkout with CRLF line endings keeps them
        $crlf = fn (string $part, string $like) => str_contains($like, "\r\n") ? str_replace("\n", "\r\n", $part) : $part;
        if ($current === null) {
            $new = $text;
        } elseif ($whole) {
            $new = $crlf($text, $current);
        } elseif (preg_match(HOUSE_BLOCK, $current, $span, PREG_OFFSET_CAPTURE) === 1) {
            $new = substr_replace($current, $crlf($block[0], $span[0][0]), $span[0][1], strlen($span[0][0]));
        } else {
            $lines[] = "not managed: {$relative} has no house:begin and house:end markers; add them around the house text, or list it under overrides";

            continue;
        }
        if ($new === $current) {
            continue;
        }
        if (! $check) {
            is_dir(dirname($target)) || mkdir(dirname($target), 0775, true) || is_dir(dirname($target)) || fail('cannot create '.dirname($target));
            file_put_contents($target, $new) === false && fail("cannot write {$target}");
        }
        $lines[] = ($check ? 'would ' : '').($current === null ? 'create' : 'update')." {$relative}";
    }
    // a module that is off leaves its house files behind; the project may hold rules of its own in them
    foreach (array_diff(HouseConfig::MODULES, $modules) as $off) {
        foreach (array_keys(templateFiles("{$templates}/modules/{$off}")) as $relative) {
            $text = (string) @file_get_contents("{$repo}/{$relative}");
            if (! isset($rendered[$relative]) && ! in_array($relative, $overrides, true) && (str_starts_with($relative, '.ai/') ? $text !== '' : preg_match(HOUSE_BLOCK, $text) === 1)) {
                $lines[] = "remove by hand {$relative}: module {$off} is off (keep any rule of this project's own elsewhere, or list it under overrides)";
            }
        }
    }
    echo $lines === [] ? '' : implode("\n", $lines)."\n";
    exit($check && $lines !== [] ? 1 : 0);
}

$written = $skipped = [];
foreach ($rendered as $relative => $text) {
    $target = "{$base}/{$relative}";
    // config/house.php holds the project's overrides and state: never overwritten, --force included
    if ($renderTo === null && file_exists($target) && (! $force || $relative === 'config/house.php') && ! in_array($relative, $replaced, true)) {
        $skipped[] = $relative;

        continue;
    }
    if (! $dryRun) {
        is_dir(dirname($target)) || mkdir(dirname($target), 0775, true) || is_dir(dirname($target)) || fail("cannot create ".dirname($target));
        file_put_contents($target, $text) === false && fail("cannot write {$target}");
        str_ends_with($target, '.sh') && chmod($target, 0755);
    }
    $written[] = $relative;
}

echo ($dryRun ? 'would write' : 'written').($renderTo !== null ? " to {$renderTo}" : '').' ('.count($written)."):\n  ".implode("\n  ", $written)."\n";
if ($skipped) {
    echo 'skipped, already exists ('.count($skipped)."):\n  ".implode("\n  ", $skipped)."\n";
}
foreach ($left as $relative => $keys) {
    echo "placeholders left in {$relative}: ".implode(', ', $keys)."\n";
}
