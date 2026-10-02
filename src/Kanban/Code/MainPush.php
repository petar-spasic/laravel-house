<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use Closure;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\RemoteFailed;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;

/** Pushes main to the remote, merging the remote's main first when it moved; never forces. */
final class MainPush
{
    public function __construct(
        private readonly Git $git,
        private readonly string $remote,
        private readonly string $branch,
    ) {}

    public function hasRemote(): bool
    {
        return $this->git->attempt(['remote', 'get-url', $this->remote])->ok();
    }

    /** Merge commits on main that no ref of the remote holds: the merges since the last push. */
    public function unpushedMerges(): int
    {
        return (int) $this->git->line(['rev-list', '--merges', '--count', 'refs/heads/'.$this->branch, '--not', '--remotes='.$this->remote]);
    }

    /** @param  Closure(string): void  $say */
    public function push(Closure $say): void
    {
        if ($this->attempt($say)) {
            return;
        }
        $this->git->run(['fetch', '-q', $this->remote, "+refs/heads/{$this->branch}:refs/remotes/{$this->remote}/{$this->branch}"]);
        $merge = $this->git->attempt(['merge', '-q', '--no-edit', "{$this->remote}/{$this->branch}"]);
        if (! $merge->ok()) {
            $this->git->attempt(['merge', '--abort']);
            throw new Conflict("{$this->branch}: merging {$this->remote}/{$this->branch} conflicts; aborted, resolve by hand", [trim($merge->out.$merge->err)]);
        }
        $say("{$this->branch}: merged {$this->remote}/{$this->branch}");
        if (! $this->attempt($say)) {
            throw new RemoteFailed("{$this->branch}: push rejected again");
        }
    }

    /** True when pushed; false when rejected as non-fast-forward. */
    private function attempt(Closure $say): bool
    {
        $result = $this->git->attempt(['push', '-q', $this->remote, "refs/heads/{$this->branch}:refs/heads/{$this->branch}"]);
        if ($result->ok()) {
            $say("{$this->branch}: pushed to {$this->remote}");

            return true;
        }
        if (preg_match('/\[rejected\]|non-fast-forward|fetch first/', $result->err) === 1) {
            return false;
        }
        throw new RemoteFailed("{$this->branch}: push failed: ".trim($result->err));
    }
}
