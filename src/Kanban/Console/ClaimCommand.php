<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:claim', hidden: true)]
class ClaimCommand extends Command
{
    protected $signature = 'kanban:claim {id : Card id or unique prefix} {--force : Skip capacity and policy checks (main only)}';

    protected $description = 'Claim a ready card (ready → doing), or a planning card for its planner, without a worktree; `start` does this and more';

    protected $hidden = true;

    protected function perform(): int
    {
        $this->requireMainOrOwner('claim');
        $card = $this->transitions()->start($this->argument('id'), $this->actor(), force: (bool) $this->option('force'));
        $this->say("claimed {$card->id()} by {$card->claim()['by']}");
        $this->reportPending();

        return self::SUCCESS;
    }
}
