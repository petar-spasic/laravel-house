<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Hooks\WorktreeRemove;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Support\Lock;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:sweep')]
class SweepCommand extends Command
{
    protected $signature = 'kanban:sweep {--reclaim : Also remove idle isolated-agent worktrees (bounded; SessionStart runs it in the background)}';

    protected $description = 'Commit journaled board writes and prune old runtime files';

    protected function perform(): int
    {
        $store = $this->store();
        $before = $store->pending();
        $store->flush($this->actor());
        $after = $store->pending();
        $this->say($before === 0 ? 'journal: empty' : 'journal: committed '.($before - $after).' of '.$before.' write(s)');

        $snapshot = $store->snapshot();
        $pruned = (new Runtime($this->paths(), $snapshot->staleMinutes()))->prune($snapshot);
        $this->say("runtime: pruned {$pruned}");

        if ($this->option('reclaim') && ($lock = Lock::try($this->paths()->ensureRuntime().'/reclaim.lock')) !== null) {
            try {
                touch($this->paths()->runtime('reclaim.last'));
                $this->say('agent worktrees: reclaimed '.(new WorktreeRemove($this->paths(), $this->config()))->reclaim());
            } finally {
                $lock->release();
            }
        }

        return $after === 0 ? self::SUCCESS : 1;
    }
}
