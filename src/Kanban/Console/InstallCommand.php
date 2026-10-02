<?php

namespace PetarSpasic\Kanban\Console;

use PetarSpasic\Kanban\Console\Install\NextSteps;
use PetarSpasic\Kanban\Store\Git\Bootstrap;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:install')]
class InstallCommand extends Command
{
    protected $signature = 'kanban:install
        {--key= : Card id prefix, e.g. SHOP (default: from the project directory)}
        {--dry-run : Print what would change}
        {--force : Re-apply machine config (hooksPath) and install steps}';

    protected $description = 'Create the kanban board (orphan branch at docs/kanban) and wire this project';

    protected function perform(): int
    {
        $paths = $this->paths();
        $key = strtoupper((string) ($this->option('key') ?? substr((string) preg_replace('/[^A-Za-z0-9]/', '', basename($paths->main)), 0, 3)));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        foreach ((new Bootstrap($paths, $this->config()))->install($key, $dryRun, $force) as $line) {
            $this->say($line);
        }
        foreach ($this->laravel->tagged(InstallStep::TAG) as $step) {
            foreach ($step->install($this, $dryRun, $force) as $line) {
                $this->say($line);
            }
        }
        if (! $dryRun) {
            $this->publishOnce();
            $this->say('next: '.NextSteps::RESTART);
        }

        return self::SUCCESS;
    }
}
