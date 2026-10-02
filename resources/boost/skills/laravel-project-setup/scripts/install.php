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
 *
 * php install.php <repo> --modules=htmx,islands,tenancy --set app=acme [--set key=value …] [--force] [--dry-run]
 *   [--render-to=<dir>] [--templates=<dir>]
 */

const MODULES = ['htmx', 'islands', 'spa', 'reverb', 'tenancy'];

$repo = null;
$modules = [];
$vars = [];
$force = false;
$dryRun = false;
$renderTo = null;
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
