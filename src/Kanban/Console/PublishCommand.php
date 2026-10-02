<?php

namespace PetarSpasic\Kanban\Console;

use PetarSpasic\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\Kanban\Store\Exceptions\RemoteFailed;
use PetarSpasic\Kanban\Support\Git;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:publish')]
class PublishCommand extends Command
{
    protected $signature = 'kanban:publish';

    protected $description = 'End of a run: sync the board, then push main (merging origin/main when it moved)';

    protected function perform(): int
    {
        SyncCommand::report($this->store()->sync(), $this->say(...));
        $main = new Git($this->paths()->main);
        $remote = (string) $this->setting('remote', 'origin');
        $branch = (string) $this->setting('main_branch', 'main');
        if (! $main->attempt(['remote', 'get-url', $remote])->ok()) {
            $this->say("{$branch}: no remote {$remote}");

            return self::SUCCESS;
        }
        if ($this->push($main, $remote, $branch)) {
            return self::SUCCESS;
        }
        $main->run(['fetch', '-q', $remote, "+refs/heads/{$branch}:refs/remotes/{$remote}/{$branch}"]);
        $merge = $main->attempt(['merge', '-q', '--no-edit', "{$remote}/{$branch}"]);
        if (! $merge->ok()) {
            $main->attempt(['merge', '--abort']);
            throw new Conflict("{$branch}: merging {$remote}/{$branch} conflicts; aborted, resolve by hand", [trim($merge->out.$merge->err)]);
        }
        $this->say("{$branch}: merged {$remote}/{$branch}");
        if (! $this->push($main, $remote, $branch)) {
            throw new RemoteFailed("{$branch}: push rejected again");
        }

        return self::SUCCESS;
    }

    /** True when pushed; false when rejected as non-fast-forward. */
    private function push(Git $main, string $remote, string $branch): bool
    {
        $result = $main->attempt(['push', '-q', $remote, "refs/heads/{$branch}:refs/heads/{$branch}"]);
        if ($result->ok()) {
            $this->say("{$branch}: pushed to {$remote}");

            return true;
        }
        if (preg_match('/\[rejected\]|non-fast-forward|fetch first/', $result->err) === 1) {
            return false;
        }
        throw new RemoteFailed("{$branch}: push failed: ".trim($result->err));
    }
}
