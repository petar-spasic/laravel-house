<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

/** Whether main holds current copies of the `worktrees.copy` dependencies that new worktrees start from. */
final class Dependencies
{
    /** The lockfile beside each dependency directory a worktree copies. */
    private const LOCKFILES = ['vendor' => 'composer.lock', 'node_modules' => 'package-lock.json'];

    /**
     * @param  list<string>  $copy  `worktrees.copy`
     * @return list<string> one line per item main lacks or holds behind its lockfile
     */
    public static function problems(string $main, array $copy): array
    {
        $problems = [];
        foreach ($copy as $item) {
            $item = trim((string) $item, '/');
            $lockfile = self::LOCKFILES[basename($item)] ?? null;
            $dir = dirname($item) === '.' ? '' : dirname($item).'/';
            if ($lockfile === null || ! is_file("{$main}/{$dir}{$lockfile}")) {
                continue;
            }
            if (! is_dir("{$main}/{$item}")) {
                $problems[] = "worktrees start without {$item}: install it in main";
            } elseif ($lockfile === 'package-lock.json' && self::behind("{$main}/{$dir}{$lockfile}", "{$main}/{$item}/.package-lock.json")) {
                $problems[] = "{$item} is behind {$dir}{$lockfile}: run npm ci in main, or rebuild main's stack";
            }
        }

        return $problems;
    }

    /**
     * npm's hidden lockfile (what node_modules holds) against package-lock.json: a package missing, extra or at another
     * version. An optional package may be missing: npm installs only the current platform's. Without either file it
     * cannot tell, so it is not behind.
     */
    private static function behind(string $lock, string $hidden): bool
    {
        $wanted = self::packages($lock);
        $installed = self::packages($hidden);
        if ($wanted === null || $installed === null) {
            return false;
        }
        foreach ($wanted as $path => $package) {
            if (isset($installed[$path]) ? ($installed[$path]['version'] ?? null) !== ($package['version'] ?? null) : empty($package['optional'])) {
                return true;
            }
        }

        return array_diff_key($installed, $wanted) !== [];
    }

    /** @return array<string, array<string, mixed>>|null the `packages` map without the root entry */
    private static function packages(string $file): ?array
    {
        $data = json_decode((string) @file_get_contents($file), true);
        if (! is_array($data) || ! is_array($data['packages'] ?? null)) {
            return null;
        }
        unset($data['packages']['']);

        return $data['packages'];
    }
}
