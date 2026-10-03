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
 *
 * php install.php <repo> --modules=htmx,islands,tenancy --set app=acme [--set key=value …] [--force] [--dry-run]
 *   [--render-to=<dir>] [--templates=<dir>] [--fresh]
 */

const MODULES = ['htmx', 'islands', 'spa', 'reverb', 'tenancy'];

$repo = null;
$modules = [];
$vars = [];
$force = false;
$dryRun = false;
$renderTo = null;
$fresh = false;
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
if ($unknown = array_diff($modules, MODULES)) {
    fail('unknown module(s): '.implode(', ', $unknown).' — known: '.implode(', ', MODULES));
}
if (in_array('spa', $modules, true) && array_intersect(['htmx', 'islands'], $modules)) {
    fail('spa excludes htmx and islands: its pages are the SvelteKit app in frontend/, Laravel renders none');
}
if (in_array('islands', $modules, true) && ! in_array('htmx', $modules, true)) {
    fail('islands requires htmx');
}
if (! preg_match('/^[a-z][a-z0-9]*$/', $vars['app'] ?? '')) {
    fail('--set app=<slug> is required: lowercase letters and digits (it names the test database, the config file and the dev accounts\' email domain)');
}

if ($fresh) {
    $renderTo === null || fail('--fresh edits the repo; it does not combine with --render-to');
    foreach (['app_name', 'web_port', 'db_port', 'redis_port'] as $key) {
        isset($vars[$key]) || fail("--fresh needs --set {$key}=…");
    }
    $commits = shell_exec('git -C '.escapeshellarg($repo).' rev-parse --verify -q HEAD 2>/dev/null');
    trim((string) $commits) === '' || fail('--fresh is for a fresh skeleton, and this repo has commits: make these edits by hand (references/adopt.md)');
    fresh($repo, $modules, $vars, $dryRun);
}

/** Every fixed edit of SKILL.md steps 3 and 6 to a fresh skeleton. */
function fresh(string $repo, array $modules, array $vars, bool $dryRun): void
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
    foreach ($delete as $relative) {
        if (file_exists("{$repo}/{$relative}")) {
            $dryRun || remove("{$repo}/{$relative}");
            $say("deleted {$relative}");
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
    $json = fn (string $file, callable $change) => $edit($file, function (string $text) use ($change) {
        $data = json_decode($text, true);
        if (! is_array($data)) {
            return null;
        }
        $encoded = json_encode($change($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $indent = preg_match('/^( +)"/m', $text, $m) ? strlen($m[1]) : 4;

        return preg_replace_callback('/^(?: {4})+/m', fn ($m) => str_repeat(' ', strlen($m[0]) / 4 * $indent), $encoded);
    });
    $json('composer.json', function (array $c) use ($on) {
        $update = (array) ($c['scripts']['post-update-cmd'] ?? []);
        in_array('@php artisan boost:update --ansi', $update, true) || $update[] = '@php artisan boost:update --ansi';
        $c['scripts']['post-update-cmd'] = $update;
        if ($on('spa')) {
            unset($c['scripts']['dev']);
            foreach ($c['scripts'] as $name => $commands) {
                if (is_array($commands)) {
                    $c['scripts'][$name] = array_values(array_filter($commands, fn ($cmd) => ! is_string($cmd) || ! str_contains($cmd, 'npm ')));
                }
            }
        }

        return $c;
    });
    if ($on('htmx')) {
        $json('package.json', function (array $p) use ($on) {
            $p['scripts']['check'] = $on('islands') ? 'svelte-check --tsconfig ./tsconfig.json' : 'tsc';

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
        if (preg_match('/^\s*(?:<!-- (if|unless):([a-z]+) -->|# (if|unless):([a-z]+))\s*$/', $line, $m)) {
            [$kind, $module] = $m[1] !== '' ? [$m[1], $m[2]] : [$m[3], $m[4]];
            in_array($module, MODULES, true) || fail("{$file}:".($n + 1)." unknown module {$module}");
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

$sources = array_merge(["{$templates}/core" => ''], ...array_map(fn ($m) => ["{$templates}/modules/{$m}" => ''], $modules));
if ($renderTo !== null) {
    $sources["{$templates}/snippets"] = 'snippets/';
}
$base = $renderTo ?? $repo;
$written = $skipped = $left = [];

foreach ($sources as $source => $prefix) {
    if (! is_dir($source)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = $prefix.preg_replace('/\.stub$/', '', substr($file->getPathname(), strlen($source) + 1));
        $target = "{$base}/{$relative}";
        $raw = file_get_contents($file->getPathname());
        $text = resolve($raw, $modules, $relative);
        if (trim($text) === '' && trim($raw) !== '') {
            continue;
        }
        $text = preg_replace_callback('/\{\{([a-z_]+)\}\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $text);

        if (preg_match_all('/\{\{([a-z_]+)\}\}/', $text, $m)) {
            $left[$relative] = array_values(array_unique($m[1]));
        }
        if ($renderTo === null && file_exists($target) && ! $force) {
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
}

sort($written);
sort($skipped);
echo ($dryRun ? 'would write' : 'written').($renderTo !== null ? " to {$renderTo}" : '').' ('.count($written)."):\n  ".implode("\n  ", $written)."\n";
if ($skipped) {
    echo 'skipped, already exists ('.count($skipped)."):\n  ".implode("\n  ", $skipped)."\n";
}
foreach ($left as $relative => $keys) {
    echo "placeholders left in {$relative}: ".implode(', ', $keys)."\n";
}
