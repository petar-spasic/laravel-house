<?php

declare(strict_types=1);

/*
 * Copies templates/core and the chosen templates/modules/<m> into a Laravel repo.
 * Resolves `<!-- if:m -->` / `<!-- unless:m -->` … `<!-- endif -->` blocks (own lines, nestable) and
 * `{{key}}` placeholders given with --set. Never overwrites an existing file without --force.
 * A `.stub` suffix is dropped on write: Boost renders any `*.blade.php` inside a skill it copies.
 *
 * php install.php <repo> --modules=htmx,islands --set app=acme [--set key=value …] [--force] [--dry-run]
 */

const MODULES = ['htmx', 'islands', 'spa', 'reverb'];

$repo = null;
$modules = [];
$vars = [];
$force = false;
$dryRun = false;
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
if ($unknown = array_diff($modules, MODULES)) {
    fail('unknown module(s): '.implode(', ', $unknown).' — known: '.implode(', ', MODULES));
}
if (in_array('islands', $modules, true) && ! in_array('htmx', $modules, true)) {
    fail('islands requires htmx');
}
if (in_array('spa', $modules, true) && in_array('htmx', $modules, true)) {
    fail('spa and htmx exclude each other');
}
if (! preg_match('/^[a-z][a-z0-9]*$/', $vars['app'] ?? '')) {
    fail('--set app=<slug> is required: lowercase letters and digits (it names the test database, the config file and the dev accounts\' email domain)');
}

function resolve(string $text, array $modules, string $file): string
{
    $kept = [];
    $stack = [];
    foreach (explode("\n", $text) as $n => $line) {
        if (preg_match('/^\s*<!-- (if|unless):([a-z]+) -->\s*$/', $line, $m)) {
            in_array($m[2], MODULES, true) || fail("{$file}:".($n + 1)." unknown module {$m[2]}");
            $stack[] = ($m[1] === 'if') === in_array($m[2], $modules, true);

            continue;
        }
        if (preg_match('/^\s*<!-- endif -->\s*$/', $line)) {
            array_pop($stack) ?? fail("{$file}:".($n + 1).' stray endif');

            continue;
        }
        if (str_contains($line, '<!-- if:') || str_contains($line, '<!-- unless:') || str_contains($line, '<!-- endif')) {
            fail("{$file}:".($n + 1).' a block marker must be alone on its line');
        }
        if (! in_array(false, $stack, true)) {
            $kept[] = $line;
        }
    }
    $stack === [] || fail("{$file}: unclosed block");

    return preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept));
}

$skill = dirname(__DIR__);
$sources = array_merge(["{$skill}/templates/core"], array_map(fn ($m) => "{$skill}/templates/modules/{$m}", $modules));
$written = $skipped = $left = [];

foreach ($sources as $source) {
    if (! is_dir($source)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = preg_replace('/\.stub$/', '', substr($file->getPathname(), strlen($source) + 1));
        $target = "{$repo}/{$relative}";
        $text = resolve(file_get_contents($file->getPathname()), $modules, $relative);
        $text = preg_replace_callback('/\{\{([a-z_]+)\}\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $text);

        if (preg_match_all('/\{\{([a-z_]+)\}\}/', $text, $m)) {
            $left[$relative] = array_values(array_unique($m[1]));
        }
        if (file_exists($target) && ! $force) {
            $skipped[] = $relative;

            continue;
        }
        if (! $dryRun) {
            is_dir(dirname($target)) || mkdir(dirname($target), 0775, true);
            file_put_contents($target, $text);
        }
        $written[] = $relative;
    }
}

sort($written);
sort($skipped);
echo ($dryRun ? 'would write' : 'written').' ('.count($written)."):\n  ".implode("\n  ", $written)."\n";
if ($skipped) {
    echo 'skipped, already exists ('.count($skipped)."):\n  ".implode("\n  ", $skipped)."\n";
}
foreach ($left as $relative => $keys) {
    echo "placeholders left in {$relative}: ".implode(', ', $keys)."\n";
}
