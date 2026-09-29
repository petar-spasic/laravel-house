<?php

namespace PetarSpasic\Kanban\Console;

use PetarSpasic\Kanban\Protocol\Runtime;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:sweep')]
class SweepCommand extends Command
{
    protected $signature = 'kanban:sweep';

    protected $description = 'Commit journaled board writes and prune old runtime files';

    protected function perform(): int
    {
        $store = $this->store();
        $before = $store->pending();
        $store->flush($this->actor());
        $after = $store->pending();
        $this->say($before === 0 ? 'journal: empty' : 'journal: committed '.($before - $after).' of '.$before.' write(s)');

        $snapshot = $store->snapshot();
        $pruned = (new Runtime($this->paths(), (int) $snapshot->setting('stale_after_minutes', 20)))->prune($snapshot);
        $this->say("runtime: pruned {$pruned}");

        return $after === 0 ? self::SUCCESS : 1;
    }
}
