<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Console\Install\ClaudeSettings;
use PetarSpasic\LaravelHouse\Kanban\Console\Install\Migrate;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\Bootstrap;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:attach')]
class AttachCommand extends Command
{
    protected $signature = 'kanban:attach {--force : Also overwrite an existing core.hooksPath}';

    protected $description = 'Check out the board at docs/kanban (local or origin kanban branch) and configure this machine and its Claude Code permissions';

    protected function perform(): int
    {
        $migrate = new Migrate($this->paths(), $this->config());
        foreach ($migrate->run() as $line) {
            $this->say($line);
        }
        if ($migrate->blocked()) {
            $this->say('stopped: '.Migrate::RUNTIME.' is still there (see above); attach waits for it, so no second deploy key is made');

            return self::FAILURE;
        }
        foreach ((new Bootstrap($this->paths(), $this->config()))->attach((bool) $this->option('force')) as $line) {
            $this->say($line);
        }
        foreach ((new ClaudeSettings($this->paths(), $this->config()))->local() as $line) {
            $this->say($line);
        }
        $this->publishOnce();

        return self::SUCCESS;
    }
}
