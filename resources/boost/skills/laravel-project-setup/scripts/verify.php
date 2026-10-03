<?php

declare(strict_types=1);

/*
 * Checks what setup must have left in a project: no template marker or placeholder but {{hosting}}, the skeleton's
 * deletions done, `.env` on Postgres and Redis, the `.gitignore` lines, `boost.json`, Boost's guidelines without the
 * advice the house overrides, one E2E test suite, and an app that boots. Prints one ✗ line per failure and exits 1.
 *
 * php verify.php <repo>
 */

$repo = rtrim($argv[1] ?? '', '/');
if ($repo === '' || ! is_file("{$repo}/artisan")) {
    fwrite(STDERR, "verify: first argument must be a Laravel repo (no artisan found)\n");
    exit(1);
}
$failures = [];
$fail = function (string $message) use (&$failures): void {
    $failures[] = $message;
};
$read = fn (string $file) => (string) @file_get_contents("{$repo}/{$file}");

$skip = ['vendor', 'node_modules', '.git', 'skills', 'storage'];
$files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS),
    fn (SplFileInfo $file) => ! ($file->isDir() && (in_array($file->getFilename(), $skip, true) || str_ends_with($file->getPathname(), '/bootstrap/cache'))),
));
foreach ($files as $file) {
    if (! $file->isFile() || $file->getSize() > 1_000_000) {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($repo) + 1);
    foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $n => $line) {
        if (preg_match('/<!-- (if|unless):|<!-- endif/', $line) || preg_match_all('/\{\{([a-z_]+)\}\}/', $line, $m) && array_diff($m[1], ['hosting']) !== []) {
            $fail("{$relative}:".($n + 1).' unresolved template marker or placeholder');
        }
    }
}

foreach (['tests/Unit', 'tests/Feature', 'database/database.sqlite', 'AGENTS.md', '.agents', '.claude/skills/deploying-to-cloud'] as $gone) {
    file_exists("{$repo}/{$gone}") && $fail("{$gone} is still there");
}
if (preg_match_all('/^(DB_CONNECTION|SESSION_DRIVER|QUEUE_CONNECTION|CACHE_STORE)=/m', $read('.env'), $m)) {
    $fail('.env still sets '.implode(', ', $m[1]).': the config defaults are Postgres and Redis');
}

$ignore = array_map('trim', explode("\n", $read('.gitignore')));
foreach (['/.claude/settings.local.json', '.env.prod'] as $line) {
    in_array($line, $ignore, true) || $fail(".gitignore lacks {$line}");
}
foreach (['frankenphp', 'public/frankenphp-worker.php'] as $path) {
    trim((string) shell_exec('git -C '.escapeshellarg($repo).' check-ignore '.escapeshellarg($path).' 2>/dev/null')) === ''
        && $fail(".gitignore does not ignore {$path}");
}

$boost = json_decode($read('boost.json'), true);
in_array('claude_code', (array) ($boost['agents'] ?? []), true) || $fail('boost.json agents lacks claude_code');
in_array('petar-spasic/laravel-house', (array) ($boost['packages'] ?? []), true) || $fail('boost.json packages lacks petar-spasic/laravel-house');
if (preg_match('#<laravel-boost-guidelines>(.*)</laravel-boost-guidelines>\s*$#s', $read('CLAUDE.md'), $block) !== 1) {
    $fail('CLAUDE.md does not end with the <laravel-boost-guidelines> block (php artisan boost:install)');
} else {
    foreach (['Test every code change', 'Unit and feature tests are more important', 'make:test', 'Laravel Cloud', 'composer run dev'] as $advice) {
        str_contains($block[1], $advice) && $fail("Boost's guidelines in CLAUDE.md still say \"{$advice}\": an override is missing (references/boost.md)");
    }
}
str_contains($read('.claude/skills/testing-best-practices/SKILL.md'), 'end to end or not at all')
    || $fail('.claude/skills/testing-best-practices is not the house one (references/boost.md)');

$phpunit = $read('phpunit.xml');
preg_match_all('/<testsuite\s+name="([^"]*)"/', $phpunit, $suites);
$suites[1] === ['E2E'] || $fail('phpunit.xml has the test suites '.(implode(', ', $suites[1]) ?: 'none').'; it has one, E2E');

if (is_file("{$repo}/vendor/autoload.php")) {
    exec('cd '.escapeshellarg($repo).' && php artisan route:list --json 2>&1', $out, $code);
    $code === 0 || $fail('php artisan route:list fails: '.trim((string) end($out)));
}

foreach ($failures as $failure) {
    echo "✗ {$failure}\n";
}
exit($failures === [] ? 0 : 1);
