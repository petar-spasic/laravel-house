<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Git;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\GitFailed;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\RemoteFailed;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Ids;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/** Git operations on the board worktree (`docs/kanban`, branch `kanban`). */
final class BoardRepo
{
    private const REBASE_SECONDS = 600;

    public const BRANCH = 'kanban';

    public const DEFAULT_AUTHOR = 'Kanban UI <kanban-ui@localhost>';

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
     * Undoes what a killed kanban sync or claim left in the board worktree: a rebase in progress and a stale
     * index.lock. A rebase kanban did not start (no marker) is the owner's: it is left alone and the write is refused.
     * Only call it while holding the board write lock, so no other kanban process is mid-rebase.
     */
    public function recover(): void
    {
        $git = $this->git();
        $gitdir = $this->adminDir();
        if ($git === null || $gitdir === null) {
            return;
        }
        $marker = is_file($this->markerFile()) ? json_decode((string) @file_get_contents($this->markerFile()), true) : null;
        $marker = is_array($marker) ? $marker : null;
        $alive = $marker !== null && self::alive((int) ($marker['pid'] ?? 0)) && time() - (int) ($marker['at'] ?? 0) < self::REBASE_SECONDS;
        if (! $alive) {
            @unlink($this->markerFile());
        }
        $lock = $gitdir.'/index.lock';
        if (! $alive && is_file($lock) && (int) @filemtime($lock) < time() - 30) {
            @unlink($lock);
        }
        if (! is_dir($gitdir.'/rebase-merge') && ! is_dir($gitdir.'/rebase-apply')) {
            return;
        }
        if ($marker === null || $alive) {
            throw new Conflict('a git rebase is in progress in '.$this->paths->relative($this->paths->board()).': finish or abort it there (`git rebase --continue|--abort`), then run kanban again');
        }
        $git->attempt(['rebase', '--abort']);
    }

    /** True while git records a rebase in the board's worktree, whoever started it. */
    public function rebasing(): bool
    {
        $gitdir = $this->adminDir();

        return $gitdir !== null && (is_dir($gitdir.'/rebase-merge') || is_dir($gitdir.'/rebase-apply'));
    }

    /** True when a kanban rebase was killed: its marker names a dead process and git still records the rebase. */
    public function abandoned(): bool
    {
        $gitdir = $this->adminDir();
        if ($gitdir === null || (! is_dir($gitdir.'/rebase-merge') && ! is_dir($gitdir.'/rebase-apply'))) {
            return false;
        }
        $marker = is_file($this->markerFile()) ? json_decode((string) @file_get_contents($this->markerFile()), true) : null;

        return is_array($marker) && ! (self::alive((int) ($marker['pid'] ?? 0)) && time() - (int) ($marker['at'] ?? 0) < self::REBASE_SECONDS);
    }

    private function markerFile(): string
    {
        return $this->paths->runtime('rebase.marker');
    }

    private static function alive(int $pid): bool
    {
        return $pid > 0 && function_exists('posix_kill') && posix_kill($pid, 0);
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

    /** The full id of HEAD, or null. */
    public function headRev(): ?string
    {
        return $this->git()?->line(['rev-parse', 'HEAD']);
    }

    public function head(): ?string
    {
        return $this->git()?->line(['rev-parse', '--short', 'HEAD']);
    }

    public function remote(): string
    {
        return (string) ($this->config['remote'] ?? 'origin');
    }

    /** git's user.name in the main checkout, or null. */
    public function userName(): ?string
    {
        $name = $this->main()->line(['config', '--get', 'user.name']);

        return $name === null || $name === '' ? null : $name;
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
        for ($attempt = 1; ; $attempt++) {
            $result = $this->required()->attempt(['fetch', '-q', $this->remote(), '+refs/heads/'.self::BRANCH.':'.$this->remoteRef()]);
            if ($result->ok()) {
                return true;
            }
            if (str_contains($result->err, "couldn't find remote ref")) {
                return false;
            }
            // Two fetches of one clone racing for the same tracking ref: the loser fails, the retry finds it current.
            if ($attempt >= 4 || preg_match('/incorrect old value|cannot lock ref .* but expected|Unable to create .*\.lock/', $result->err) !== 1) {
                throw new RemoteFailed('fetch failed: '.trim($result->err));
            }
            usleep(50000 * $attempt);
        }
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
        $this->paths->ensureRuntime();
        file_put_contents($this->markerFile(), json_encode(['pid' => getmypid(), 'at' => time()]));
        try {
            $result = $git->attempt([...$this->driverArguments(), 'rebase', '-q', $this->remoteRef()]);
            if (! $result->ok()) {
                $git->attempt(['rebase', '--abort']);
            }
        } finally {
            @unlink($this->markerFile());
        }
        if (! $result->ok()) {
            throw new Conflict('rebase onto '.$this->remote().'/'.self::BRANCH.' failed; aborted: '.trim($result->err ?: $result->out));
        }

        return $behind;
    }

    /**
     * The merge driver of this rebase: the running package's own bin/kanban, not the path .git/config holds. That path
     * belongs to the machine that ran `attach` (a container sees the host's), and a rebase whose driver cannot run aborts.
     *
     * @return list<string>
     */
    private function driverArguments(): array
    {
        $driver = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 4).'/bin/kanban').' merge-driver %O %A %B %P';

        return ['-c', 'merge.kanban.name=laravel-house kanban JSON merge', '-c', 'merge.kanban.driver='.$driver];
    }

    /** 'ok' or 'rejected' (non-fast-forward); RemoteFailed for anything else. */
    public function push(float $timeout = 120): string
    {
        $result = $this->required()->attempt(['push', '-q', '--set-upstream', $this->remote(), 'refs/heads/'.self::BRANCH.':refs/heads/'.self::BRANCH], null, $timeout);
        if ($result->ok()) {
            return 'ok';
        }
        // older git names a lost ref race only on a `remote: error: cannot lock ref … but expected` line; the [remote rejected] line just says `failed to update ref`
        if (preg_match('/\[rejected\]|non-fast-forward|fetch first|cannot lock ref|\[remote rejected\].*(stale|lock|incorrect old value)/', $result->err) === 1) {
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
        if ($git->attempt(['diff', '--cached', '--quiet'])->code === 0) {
            return;
        }
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

    /** The commit where HEAD forked from origin/kanban. */
    public function mergeBase(): ?string
    {
        return $this->hasRemoteRef() ? $this->git()?->line(['merge-base', 'HEAD', $this->remoteRef()]) : null;
    }

    /** Contents of $path at $rev, or null when absent. */
    public function show(string $rev, string $path): ?string
    {
        $result = $this->git()?->attempt(['show', $rev.':'.$path]);

        return $result !== null && $result->ok() ? $result->out : null;
    }

    /** @return array<string, string> card id => path, where HEAD forked from origin/kanban */
    public function baseIds(): array
    {
        $git = $this->git();
        $base = $this->hasRemoteRef() ? $git?->line(['merge-base', 'HEAD', $this->remoteRef()]) : null;

        return $base === null ? [] : $this->ids((string) $git?->line(['ls-tree', '-r', '--name-only', $base]));
    }

    /** @return array<string, string> card id => path, on HEAD */
    public function headIds(): array
    {
        return $this->ids((string) $this->git()?->line(['ls-tree', '-r', '--name-only', 'HEAD']));
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
