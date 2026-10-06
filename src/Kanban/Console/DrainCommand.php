<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Turns `kanban run` into a drain without stopping it: the run checks `run.drain` between passes, so no merge or launch is
 * cut short. The flag stays across runs (an `--until-attention` run returns and is started again) until one has drained.
 */
#[AsCommand(name: 'kanban:drain')]
class DrainCommand extends Command
{
    protected $signature = 'kanban:drain {--off : Run normally again}';

    protected $description = 'Make kanban run wrap up from its next pass: no new card, parked work finished, return once none is in flight';

    protected function perform(): int
    {
        $this->requireMainOrOwner('drain');
        $flag = $this->paths()->runtime(RunCommand::DRAIN);
        if ($this->option('off')) {
            @unlink($flag);
            $this->say('drain off: kanban run starts new cards again');

            return self::SUCCESS;
        }
        $this->paths()->ensureRuntime();
        touch($flag);
        $pid = RunCommand::running($this->paths());
        $this->say($pid === null
            ? 'drain on: the next `kanban run` starts no new card and returns once none is in flight'
            : "drain on: kanban run (pid {$pid}) starts no new card from its next pass and returns once none is in flight");

        return self::SUCCESS;
    }
}
