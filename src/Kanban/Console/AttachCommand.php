<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Console\Install\Migrate;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\Bootstrap;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:attach')]
class AttachCommand extends Command
{
    protected $signature = 'kanban:attach {--force : Also overwrite an existing core.hooksPath}';

    protected $description = 'Check out the board at docs/kanban (local or origin kanban branch) and configure this machine';

    protected function perform(): int
    {
        foreach ([...(new Migrate($this->paths(), $this->config()))->run(), ...(new Bootstrap($this->paths(), $this->config()))->attach((bool) $this->option('force'))] as $line) {
            $this->say($line);
        }
        $this->publishOnce();

        return self::SUCCESS;
    }
}
