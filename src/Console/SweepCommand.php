<?php

namespace PetarSpasic\Kanban\Console;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:sweep')]
class SweepCommand extends Command
{
    protected $signature = 'kanban:sweep';

    protected $description = 'Commit journaled board writes and prune old runtime files';

    private const APPLIED_DAYS = 7;

    protected function perform(): int
    {
        $store = $this->store();
        $before = $store->pending();
        $store->flush($this->actor());
        $after = $store->pending();
        $this->say($before === 0 ? 'journal: empty' : 'journal: committed '.($before - $after).' of '.$before.' write(s)');

        $pruned = 0;
        foreach (glob($this->paths()->applied('*.json')) ?: [] as $file) {
            if (filemtime($file) < time() - self::APPLIED_DAYS * 86400 && @unlink($file)) {
                $pruned++;
            }
        }
        $this->say("applied: pruned {$pruned}");

        return $after === 0 ? self::SUCCESS : 1;
    }
}
