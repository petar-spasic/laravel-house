<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use Closure;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\RemoteFailed;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\BoardRepo;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/** Main on the remote: fetched, asked whether it holds a commit, pushed as a fast-forward; never forced, never merged into. */
final class MainPush
{
    public function __construct(
        private readonly Git $git,
        private readonly string $remote,
        private readonly string $branch,
    ) {}

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public static function of(Paths $paths, array $config): self
    {
        return new self(new Git($paths->main), (string) ($config['remote'] ?? 'origin'), (string) ($config['main_branch'] ?? 'main'));
    }

    /** Main's tip as this machine knows it, without asking the remote: the remote's as last fetched, else local main. */
    public function known(): ?string
    {
        return $this->git->line(['rev-parse', '--verify', '-q', $this->hasRemote() ? $this->tracking() : 'refs/heads/'.$this->branch]);
    }

    public function hasRemote(): bool
    {
        return $this->git->attempt(['remote', 'get-url', $this->remote])->ok();
    }

    /** The remote's main as fetched into its tracking ref, or null when the remote has none; RemoteFailed when unreachable. */
    public function fetchMain(float $timeout = 60): ?string
    {
        if (! BoardRepo::fetchRef($this->git, $this->remote, "+refs/heads/{$this->branch}:{$this->tracking()}", $timeout)) {
            return null;
        }

        return $this->git->line(['rev-parse', '--verify', '-q', $this->tracking()]);
    }

    /** Whether the remote's main holds $sha, asked anew; RemoteFailed when the remote does not answer, so "no" is never a guess. */
    public function landed(string $sha): bool
    {
        $main = $this->fetchMain(10);

        return $main !== null && $this->git->attempt(['merge-base', '--is-ancestor', $sha, $main])->ok();
    }

    /** Pushes $sha as the remote's main: 'ok', or 'rejected' when main moved there; RemoteFailed on any other failure. */
    public function pushSha(string $sha, int $timeout = 60): string
    {
        $result = $this->git->attempt(['push', '-q', $this->remote, "{$sha}:refs/heads/{$this->branch}"], null, $timeout);
        if ($result->ok()) {
            return 'ok';
        }
        if (preg_match(BoardRepo::REJECTED, $result->err) === 1) {
            return 'rejected';
        }
        throw new RemoteFailed("{$this->branch}: push failed: ".trim($result->err));
    }

    /**
     * `kanban publish`: pushes local main when it is ahead of the remote's, which only commits made outside the merge
     * queue make. Diverged is refused: the queue never merges the remote's main for you.
     *
     * @param  Closure(string): void  $say
     */
    public function push(Closure $say): void
    {
        $remote = $this->fetchMain();
        $local = $this->git->line(['rev-parse', '--verify', '-q', 'refs/heads/'.$this->branch]) ?? throw new PolicyRefused("no local {$this->branch}");
        $ahead = fn (string $from, string $to) => $this->git->attempt(['merge-base', '--is-ancestor', $from, $to])->ok();
        if ($remote === $local) {
            $say("{$this->branch}: up to date");

            return;
        }
        if ($remote !== null && $ahead($local, $remote)) {
            $say("{$this->branch}: behind {$this->remote}/{$this->branch}, nothing to push");

            return;
        }
        if ($remote !== null && ! $ahead($remote, $local)) {
            throw new PolicyRefused("{$this->branch} and {$this->remote}/{$this->branch} diverged: merge or rebase by hand; the queue never merges {$this->remote}/{$this->branch} for you");
        }
        $count = (int) $this->git->line(['rev-list', '--count', $local, ...($remote === null ? [] : ['^'.$remote])]);
        if ($this->pushSha($local) === 'rejected') {
            throw new RemoteFailed("{$this->branch}: push rejected: {$this->remote}/{$this->branch} moved; run publish again");
        }
        $say("{$this->branch}: pushed {$count} commit(s) made outside the queue");
    }

    private function tracking(): string
    {
        return "refs/remotes/{$this->remote}/{$this->branch}";
    }
}
