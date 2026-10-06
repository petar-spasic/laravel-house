<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;

/** Read-only checks before a card branch is merged into main. */
final class MergeCheck
{
    /** What a card changes only with the owner's look: the agents' settings and hooks, the board's config, git's. */
    public const PROTECTED = ['.claude/', 'config/kanban.php', '.gitattributes', 'githooks/', '.githooks/', '.husky/'];

    /**
     * @param  list<string>  $files
     * @return list<string> the files under PROTECTED
     */
    public static function protected(array $files): array
    {
        return array_values(array_filter($files, fn (string $f) => array_filter(self::PROTECTED,
            fn (string $p) => str_ends_with($p, '/') ? str_starts_with($f, $p) : $f === $p) !== []));
    }

    /**
     * The files under PROTECTED the owner has not approved for this card: $allowed holds paths, or directories ending in
     * `/`.
     *
     * @param  list<string>  $files
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public static function unapproved(array $files, array $allowed): array
    {
        return array_values(array_filter(self::protected($files), fn (string $f) => array_filter($allowed,
            fn (string $a) => $a === $f || (str_ends_with($a, '/') && str_starts_with($f, $a))) === []));
    }

    /**
     * What the owner approved for $card: `allow-steering`, or the answer to the question `finish --ask` put.
     *
     * @return list<string>
     */
    public static function allowed(Card $card): array
    {
        $paths = [];
        foreach ($card->log() as $entry) {
            if (($entry['event'] ?? null) === 'steering_approved') {
                array_push($paths, ...array_map('strval', (array) ($entry['files'] ?? [])));
            }
        }

        return array_values(array_unique($paths));
    }

    /** Files whose change means a stack must be rebuilt and recreated; a lockfile at any depth. */
    public const REBUILD = ['composer.lock', 'package-lock.json', 'Dockerfile.local', 'docker/'];

    private const LOCKFILES = ['composer.lock', 'package-lock.json'];

    public function __construct(private readonly Git $git, private readonly string $main) {}

    /** @return list<string> files the branch changed since it forked from main */
    public function branchFiles(string $branch): array
    {
        return $this->names(['diff', '--name-only', "refs/heads/{$this->main}...refs/heads/{$branch}"]);
    }

    /**
     * Files main changed since $base that the branch also changed, except those matching an $ignore glob (empty when main
     * has not moved).
     *
     * @param  list<string>  $ignore
     * @return list<string>
     */
    public function movedOverlap(string $base, string $branch, array $ignore = []): array
    {
        $head = $this->git->line(['rev-parse', 'refs/heads/'.$this->main]);
        if ($head === $base) {
            return [];
        }
        $overlap = array_intersect($this->names(['diff', '--name-only', $base, 'refs/heads/'.$this->main]), $this->branchFiles($branch));

        return array_values(array_filter($overlap, function (string $file) use ($ignore) {
            foreach ($ignore as $glob) {
                if (fnmatch((string) $glob, $file)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * `git merge-tree --write-tree --name-only`: null when the merge is clean, else the conflicted files.
     *
     * @return list<string>|null
     */
    public function conflicts(string $branch): ?array
    {
        $result = $this->git->attempt(['merge-tree', '--write-tree', '--name-only', 'refs/heads/'.$this->main, 'refs/heads/'.$branch]);
        if ($result->ok()) {
            return null;
        }
        $lines = explode("\n", $result->out);
        array_shift($lines);
        $files = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                break;
            }
            $files[] = $line;
        }

        return $files === [] ? [trim($result->err) ?: 'merge-tree failed'] : $files;
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

    /**
     * Uncommitted files of the main checkout that the branch also changes.
     *
     * @param  list<string>  $branchFiles
     * @return list<string>
     */
    public function uncommittedOverlap(array $branchFiles): array
    {
        $dirty = [];
        foreach (explode("\n", $this->git->attempt(['status', '--porcelain', '--untracked-files=all'])->out) as $line) {
            if (strlen($line) > 3) {
                foreach (explode(' -> ', substr($line, 3)) as $file) {
                    $dirty[] = trim($file, '"');
                }
            }
        }

        return array_values(array_intersect($branchFiles, $dirty));
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

    /** @return list<string> */
    private function names(array $args): array
    {
        $result = $this->git->attempt($args);

        return $result->ok() ? array_values(array_filter(explode("\n", trim($result->out)))) : [];
    }
}
