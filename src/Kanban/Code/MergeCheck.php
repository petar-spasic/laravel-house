<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
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
     * The files under PROTECTED the owner has not approved for the card. An approval names paths (a directory ends in
     * `/`). One given once the branch had changed a file holds that file's content then (`blobs`), so a later change asks
     * again; one given before, at planning, covers any change.
     *
     * @param  list<string>  $files
     * @param  list<array<string, mixed>>  $approvals  the card's `steering_approved` log entries
     * @param  callable(string): string  $blob  a file's blob on the branch now, '' when it is deleted
     * @return list<string>
     */
    public static function unapproved(array $files, array $approvals, callable $blob): array
    {
        return array_values(array_filter(self::protected($files), function (string $file) use ($approvals, $blob) {
            foreach ($approvals as $approval) {
                $blobs = (array) ($approval['blobs'] ?? []);
                if (array_key_exists($file, $blobs) ? $blobs[$file] === $blob($file) : $blobs === [] && self::covers((array) ($approval['files'] ?? []), $file)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * The card's approvals (`allow-steering`, or answer 1 to the question `finish --ask` put).
     *
     * @return list<array<string, mixed>>
     */
    public static function approvals(Card $card): array
    {
        return array_values(array_filter($card->log(), fn (array $e) => ($e['event'] ?? null) === 'steering_approved'));
    }

    /**
     * The files the last `finish --ask` asked the owner about: the only ones an answer approves.
     *
     * @return list<string>
     */
    public static function asked(Card $card): array
    {
        $asked = [];
        foreach ($card->log() as $entry) {
            if (($entry['event'] ?? null) === 'steering_asked') {
                $asked = array_map('strval', (array) ($entry['files'] ?? []));
            }
        }

        return $asked;
    }

    /**
     * The log entry approving $paths for the card on $branch, with the content of each file the branch has changed by now.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    public function approval(string $branch, array $paths): array
    {
        $blobs = [];
        if ($branch !== '' && $this->git->line(['rev-parse', '--verify', '-q', "refs/heads/{$branch}"]) !== null) {
            foreach (self::protected($this->branchFiles($branch)) as $file) {
                if (self::covers($paths, $file)) {
                    $blobs[$file] = $this->blob("refs/heads/{$branch}", $file);
                }
            }
        }

        return ['event' => 'steering_approved', 'files' => $paths] + ($blobs === [] ? [] : ['blobs' => $blobs]);
    }

    /**
     * approval() for $card, its clone's branch brought into main first.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    public static function approvalOf(Worktrees $worktrees, string $main, Card $card, array $paths): array
    {
        $branch = (string) ($card->work()['branch'] ?? '');
        $clone = $main.'/'.($card->work()['worktree'] ?? "\0");
        if (is_dir($clone)) {
            $worktrees->sync($clone, $branch === '' ? null : $branch);
        }
        // a card at work whose branch is elsewhere: what the approval covers is only known where the branch is
        if (in_array($card->stage(), ['doing', 'review'], true) && ($branch === '' || $worktrees->git()->line(['rev-parse', '--verify', '-q', "refs/heads/{$branch}"]) === null)) {
            throw new PolicyRefused("{$card->id()}'s branch is not here: approve it on the machine that holds it".(($card->work()['host'] ?? null) !== null ? " ({$card->work()['host']})" : ''));
        }

        return (new self($worktrees->git(), $worktrees->mainBranch()))->approval($branch, $paths);
    }

    /** $file's blob at $rev, '' when it is not there. */
    public function blob(string $rev, string $file): string
    {
        return (string) $this->git->line(['rev-parse', '--verify', '-q', "{$rev}:{$file}"]);
    }

    /** @param  list<string>  $paths  files, or directories ending in `/` */
    private static function covers(array $paths, string $file): bool
    {
        return array_filter($paths, fn (string $p) => $p === $file || (str_ends_with($p, '/') && str_starts_with($file, $p))) !== [];
    }

    /** Files whose change means a stack must be rebuilt and recreated; a lockfile at any depth. */
    public const REBUILD = ['composer.lock', 'package-lock.json', 'Dockerfile.local', 'docker/'];

    private const LOCKFILES = ['composer.lock', 'package-lock.json'];

    public function __construct(private readonly Git $git, private readonly string $main) {}

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
