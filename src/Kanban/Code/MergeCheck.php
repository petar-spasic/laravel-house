<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Support\Git;

/** Read-only checks before a card branch is merged into main. */
final class MergeCheck
{
    /** Files whose change means the main stack must be rebuilt. */
    private const REBUILD = ['composer.lock', 'package-lock.json', 'Dockerfile.local', 'docker/'];

    public function __construct(private readonly Git $git, private readonly string $main) {}

    /** @return list<string> files the branch changed since it forked from main */
    public function branchFiles(string $branch): array
    {
        return $this->names(['diff', '--name-only', "refs/heads/{$this->main}...refs/heads/{$branch}"]);
    }

    /**
     * Files main changed since $base that the branch also changed (empty when main has not moved).
     *
     * @return list<string>
     */
    public function movedOverlap(string $base, string $branch): array
    {
        $head = $this->git->line(['rev-parse', 'refs/heads/'.$this->main]);
        if ($head === $base) {
            return [];
        }

        return array_values(array_intersect($this->names(['diff', '--name-only', $base, 'refs/heads/'.$this->main]), $this->branchFiles($branch)));
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

    /**
     * @param  list<string>  $files
     * @return list<string> the files among them that require a rebuild of the main stack
     */
    public static function rebuildFiles(array $files, ?string $compose = null): array
    {
        $patterns = $compose === null ? self::REBUILD : [...self::REBUILD, $compose];

        return array_values(array_filter($files, function (string $file) use ($patterns) {
            foreach ($patterns as $pattern) {
                if ($file === $pattern || (str_ends_with($pattern, '/') && str_starts_with($file, $pattern))) {
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
