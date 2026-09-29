<?php

namespace PetarSpasic\Kanban\Store\Git;

use PetarSpasic\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\Kanban\Store\Exceptions\GitFailed;
use PetarSpasic\Kanban\Store\Exceptions\RemoteFailed;
use PetarSpasic\Kanban\Support\Git;
use PetarSpasic\Kanban\Support\Ids;
use PetarSpasic\Kanban\Support\Paths;

/** Git operations on the board worktree (`docs/kanban`, branch `kanban`). */
final class BoardRepo
{
    public const BRANCH = 'kanban';

    private const DEFAULT_AUTHOR = 'Kanban UI <kanban-ui@localhost>';

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config = [],
    ) {}

    /**
     * Git bound to the board worktree. When the worktree's `.git` file points at a path that does not exist here
     * (the app container sees the repo at /app), the worktree's admin dir is addressed through main's `.git`.
     */
    public function git(): ?Git
    {
        $board = $this->paths->board();
        $gitFile = $board.'/.git';
        $gitdir = Paths::gitdirOf($gitFile);
        if ($gitdir === null) {
            return is_dir($gitFile) ? new Git($board) : null;
        }
        if (is_dir($gitdir)) {
            return new Git($board);
        }
        $derived = $this->paths->gitDir().'/worktrees/'.basename($gitdir);
        if (! is_dir($derived)) {
            return null;
        }

        return new Git($board, ['--git-dir='.$derived, '--work-tree='.$board], $this->author());
    }

    /**
     * Undoes what a killed sync or claim left in the board worktree: a rebase in progress (detached HEAD) and a
     * stale index.lock. Only call it while holding the board write lock, so no kanban process is mid-rebase.
     */
    public function recover(): void
    {
        $git = $this->git();
        $gitdir = $this->adminDir();
        if ($git === null || $gitdir === null) {
            return;
        }
        $lock = $gitdir.'/index.lock';
        if (is_file($lock) && (int) @filemtime($lock) < time() - 30) {
            @unlink($lock);
        }
        if (is_dir($gitdir.'/rebase-merge') || is_dir($gitdir.'/rebase-apply')) {
            $git->attempt(['rebase', '--abort']);
        }
    }

    /** True while a rebase (killed before it finished) is still recorded in the board worktree. */
    public function interrupted(): bool
    {
        $gitdir = $this->adminDir();

        return $gitdir !== null && (is_dir($gitdir.'/rebase-merge') || is_dir($gitdir.'/rebase-apply'));
    }

    private function adminDir(): ?string
    {
        $gitdir = Paths::gitdirOf($this->paths->board('.git'));

        return $gitdir === null || is_dir($gitdir) ? $gitdir : $this->paths->gitDir().'/worktrees/'.basename($gitdir);
    }

    public function isContainer(): bool
    {
        $gitdir = Paths::gitdirOf($this->paths->board('.git'));

        return $gitdir !== null && ! is_dir($gitdir);
    }

    public function main(): Git
    {
        return new Git($this->paths->main);
    }

    /** Stages and commits the given paths (relative to the board). False when git is unusable. */
    public function commit(array $paths, string $message): bool
    {
        $git = $this->git();
        if ($git === null || $paths === []) {
            return $git !== null;
        }
        if (! $git->attempt(['add', '-A', '--', ...$paths])->ok()) {
            return false;
        }
        $staged = $git->attempt(['diff', '--cached', '--quiet', '--', ...$paths]);
        if ($staged->code === 0) {
            return true;
        }
        if ($staged->code !== 1) {
            return false;
        }

        return $git->attempt(['commit', '-q', '--no-verify', '-m', $message, '--', ...$paths])->ok();
    }

    public function head(): ?string
    {
        return $this->git()?->line(['rev-parse', '--short', 'HEAD']);
    }

    public function remote(): string
    {
        return (string) ($this->config['remote'] ?? 'origin');
    }

    public function hasRemote(): bool
    {
        return $this->main()->attempt(['remote', 'get-url', $this->remote()])->ok();
    }

    public function remoteRef(): string
    {
        return 'refs/remotes/'.$this->remote().'/'.self::BRANCH;
    }

    public function hasRemoteRef(): bool
    {
        return (bool) $this->git()?->attempt(['rev-parse', '--verify', '-q', $this->remoteRef()])->ok();
    }

    /** Fetches origin's kanban branch. False when origin has no such branch; RemoteFailed when unreachable. */
    public function fetch(): bool
    {
        $result = $this->required()->attempt(['fetch', '-q', $this->remote(), '+refs/heads/'.self::BRANCH.':'.$this->remoteRef()]);
        if ($result->ok()) {
            return true;
        }
        if (str_contains($result->err, "couldn't find remote ref")) {
            return false;
        }
        throw new RemoteFailed('fetch failed: '.trim($result->err));
    }

    /** Commits on HEAD that origin/kanban lacks (all of them when origin has no branch). */
    public function ahead(): int
    {
        $range = $this->hasRemoteRef() ? [$this->remoteRef().'..HEAD'] : ['HEAD'];

        return (int) $this->required()->line(['rev-list', '--count', ...$range]);
    }

    public function behind(): int
    {
        return $this->hasRemoteRef() ? (int) $this->required()->line(['rev-list', '--count', 'HEAD..'.$this->remoteRef()]) : 0;
    }

    /** Rebases onto origin/kanban with the merge driver; aborts and throws Conflict on failure. Returns commits pulled. */
    public function rebase(): int
    {
        $behind = $this->behind();
        if ($behind === 0) {
            return 0;
        }
        $git = $this->required();
        $git->attempt(['update-index', '-q', '--refresh']);
        $result = $git->attempt(['rebase', '-q', $this->remoteRef()]);
        if (! $result->ok()) {
            $git->attempt(['rebase', '--abort']);
            throw new Conflict('rebase onto '.$this->remote().'/'.self::BRANCH.' failed; aborted: '.trim($result->err ?: $result->out));
        }

        return $behind;
    }

    /** 'ok' or 'rejected' (non-fast-forward); RemoteFailed for anything else. */
    public function push(): string
    {
        $result = $this->required()->attempt(['push', '-q', '--set-upstream', $this->remote(), 'refs/heads/'.self::BRANCH.':refs/heads/'.self::BRANCH]);
        if ($result->ok()) {
            return 'ok';
        }
        if (preg_match('/\[rejected\]|non-fast-forward|fetch first|\[remote rejected\].*(stale|lock|incorrect old value)/', $result->err) === 1) {
            return 'rejected';
        }
        throw new RemoteFailed('push failed: '.trim($result->err));
    }

    /**
     * Squashes the commits origin/kanban lacks into one, so a rebase replays the final state only (after a re-id
     * the original "created" commit would otherwise collide with origin's card on the same path).
     */
    public function squashUnpushed(string $subject): void
    {
        $git = $this->required();
        $base = trim($git->run(['merge-base', 'HEAD', $this->remoteRef()]));
        $subjects = trim($git->run(['log', '--reverse', '--format=%s', $base.'..HEAD']));
        $git->run(['reset', '-q', '--soft', $base]);
        $git->run(['commit', '-q', '--no-verify', '-m', $subject, '-m', $subjects]);
    }

    public function resetKeep(string $rev): void
    {
        $git = $this->required();
        $git->attempt(['update-index', '-q', '--refresh']);
        $git->run(['reset', '-q', '--keep', $rev]);
    }

    /** Blob id of $path at $rev, or null when absent. */
    public function blob(string $rev, string $path): ?string
    {
        return $this->git()?->line(['rev-parse', '-q', '--verify', $rev.':'.$path]);
    }

    /** @return array<string, string> card id => path, on origin/kanban */
    public function remoteIds(): array
    {
        if (! $this->hasRemoteRef()) {
            return [];
        }

        return $this->ids((string) $this->git()?->line(['ls-tree', '-r', '--name-only', $this->remoteRef()]));
    }

    /** @return list<string> paths added on HEAD since it forked from origin/kanban */
    public function addedLocally(): array
    {
        if (! $this->hasRemoteRef()) {
            return [];
        }
        $out = (string) $this->git()?->line(['diff', '--name-only', '--diff-filter=A', $this->remoteRef().'...HEAD']);

        return array_values(array_filter(explode("\n", $out)));
    }

    public function mergeDriver(): ?string
    {
        return $this->main()->line(['config', '--get', 'merge.kanban.driver']);
    }

    /** @return array<string, string> */
    private function ids(string $listing): array
    {
        $ids = [];
        foreach (array_filter(explode("\n", $listing)) as $path) {
            $stem = basename($path, '.json');
            if (str_ends_with($path, '.json') && Ids::isValid($stem)) {
                $ids[$stem] = $path;
            }
        }

        return $ids;
    }

    private function required(): Git
    {
        return $this->git() ?? throw new GitFailed('the board worktree has no usable git here');
    }

    /** @return array<string, string> */
    private function author(): array
    {
        $author = (string) ($this->config['ui']['git_author'] ?? '') ?: self::DEFAULT_AUTHOR;
        preg_match('/^(.*?)\s*<([^>]+)>$/', $author, $m);
        [$name, $email] = [$m[1] ?? $author, $m[2] ?? 'kanban-ui@localhost'];

        return ['GIT_AUTHOR_NAME' => $name, 'GIT_AUTHOR_EMAIL' => $email, 'GIT_COMMITTER_NAME' => $name, 'GIT_COMMITTER_EMAIL' => $email];
    }
}
