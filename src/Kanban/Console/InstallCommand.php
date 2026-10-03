<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Console\Install\Migrate;
use PetarSpasic\LaravelHouse\Kanban\Console\Install\NextSteps;
use PetarSpasic\LaravelHouse\Kanban\Console\Install\Prerequisites;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\Bootstrap;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:install')]
class InstallCommand extends Command
{
    protected $signature = 'kanban:install
        {--key= : Card id prefix, e.g. SHOP (default: from the project directory)}
        {--dry-run : Print what would change}
        {--force : Re-apply machine config (hooksPath) and install steps}
        {--check : Only check the prerequisites (exit 1 on a fail)}';

    protected $description = 'Create the kanban board (orphan branch at docs/kanban) and wire this project';

    protected function perform(): int
    {
        $paths = $this->paths();
        $key = strtoupper((string) ($this->option('key') ?? substr((string) preg_replace('/[^A-Za-z0-9]/', '', basename($paths->main)), 0, 3)));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $failed = false;
        foreach ((new Prerequisites($paths, $this->config()))->check() as [$level, $text]) {
            $failed = $failed || $level === 'fail';
            if ($this->option('check') || $level !== 'ok') {
                $this->say("{$level} {$text}");
            }
        }
        if ($this->option('check') || ($failed && ! $dryRun)) {
            $failed && ! $this->option('check') && $this->say('stopped: fix the fail lines above, then install again');

            return $failed ? self::FAILURE : self::SUCCESS;
        }
        $migrate = new Migrate($paths, $this->config());
        foreach ($migrate->run($dryRun) as $line) {
            $this->say($line);
        }
        if (! $dryRun && $migrate->blocked()) {
            $this->say('stopped: '.Migrate::RUNTIME.' is still there (see above); attach waits for it, so no second deploy key is made');

            return self::FAILURE;
        }
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
