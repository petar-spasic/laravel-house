<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Support\Git;

/** Read-only checks of what a card branch brings to main. */
final class MergeCheck
{
    /** Files whose change means a stack must be rebuilt and recreated; a lockfile at any depth. */
    public const REBUILD = ['composer.lock', 'package-lock.json', 'Dockerfile.local', 'docker/'];

    private const LOCKFILES = ['composer.lock', 'package-lock.json'];

    public function __construct(private readonly Git $git) {}

    /**
     * The merges in $range whose result holds what neither parent had: a file that differs from git's own merge of the two
     * parents and was no conflict there (resolving a conflict is the merge's to do). One `sha: files` line each.
     *
     * @return list<string>
     */
    public function evilMerges(string $range): array
    {
        $found = [];
        foreach (array_filter(explode("\n", $this->git->attempt(['rev-list', '--merges', '--parents', $range])->out)) as $line) {
            $shas = explode(' ', trim($line));
            if (count($shas) !== 3) {
                continue;
            }
            [$merge, $ours, $theirs] = $shas;
            $auto = explode("\n", trim($this->git->attempt(['merge-tree', '--write-tree', '--name-only', '--no-messages', $ours, $theirs])->out));
            $tree = array_shift($auto);
            if (preg_match('/^[0-9a-f]{40,64}$/', (string) $tree) !== 1) {
                continue;
            }
            $changed = array_filter(explode("\n", trim($this->git->attempt(['diff', '--name-only', $tree, $merge])->out)));
            if (($added = array_values(array_diff($changed, $auto))) !== []) {
                $found[] = substr($merge, 0, 7).': '.implode(', ', array_slice($added, 0, 10)).(count($added) > 10 ? ' and '.(count($added) - 10).' more' : '');
            }
        }

        return $found;
    }

    public function has(string $commit): bool
    {
        return $this->git->attempt(['cat-file', '-e', $commit.'^{commit}'])->ok();
    }

    /**
     * Conflict markers on lines added from $from to $to (`path:line`): `diff --check` with every whitespace rule off, so
     * Markdown line breaks pass and only full-width markers on added lines count.
     *
     * @return list<string>
     */
    public function markers(string $from, string $to = 'HEAD'): array
    {
        $result = $this->git->attempt(['-c', 'core.whitespace=-blank-at-eol,-blank-at-eof,-space-before-tab,-indent-with-non-tab,-tab-in-indent,-cr-at-eol',
            'diff', '--check', "{$from}...{$to}"]);
        preg_match_all('/^(.+:\d+): leftover conflict marker$/m', $result->out, $m);

        return $m[1];
    }

    /** @param  list<string>  $markers  what markers() found; the refusal names the first 20 */
    public static function markersMessage(array $markers): string
    {
        return "Leftover conflict markers; resolve them and commit:\n".implode("\n", array_slice($markers, 0, 20))
            .(count($markers) > 20 ? "\n… ".(count($markers) - 20).' more' : '');
    }

    /** @return list<string> untracked, not ignored files of the main checkout */
    public function untracked(): array
    {
        $lines = explode("\n", $this->git->attempt(['status', '--porcelain', '--untracked-files=normal'])->out);

        return array_values(array_map(fn (string $l) => trim(substr($l, 3), '"'), array_filter($lines, fn (string $l) => str_starts_with($l, '?? '))));
    }

    /**
     * @param  list<string>  $files
     * @return list<string> the files among them that require a rebuild of the main stack
     */
    public static function rebuildFiles(array $files, ?string $compose = null): array
    {
        $patterns = $compose === null ? self::REBUILD : [...self::REBUILD, $compose];

        return array_values(array_filter($files, function (string $file) use ($patterns) {
            foreach ($patterns as $pattern) {
                if ($file === $pattern || (str_ends_with($pattern, '/') && str_starts_with($file, $pattern))
                    || (in_array($pattern, self::LOCKFILES, true) && basename($file) === $pattern)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
