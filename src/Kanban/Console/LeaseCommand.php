<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:lease')]
class LeaseCommand extends Command
{
    protected $signature = 'kanban:lease
        {--takeover : Take the orchestrator lease from another session}
        {--release : Give up this session\'s lease}';

    protected $description = 'The orchestrator lease: one main session per machine drives the board';

    protected function perform(): int
    {
        $lease = new Lease($this->paths());
        $actor = $this->actor();
        if ($this->option('takeover') || $this->option('release')) {
            $this->requireMainCheckout('lease');
        }
        if ($this->option('takeover')) {
            $previous = $lease->takeover($actor);
            $this->say('lease: this session ('.$actor->session.')'.($previous !== null ? ", taken over from {$previous['session']}" : ''));

            return self::SUCCESS;
        }
        if ($this->option('release')) {
            $this->say($lease->release($actor) ? 'lease released' : 'lease: not held by this session');

            return self::SUCCESS;
        }
        $this->say('lease: '.$lease->describe($actor->session));

        return self::SUCCESS;
    }
}
