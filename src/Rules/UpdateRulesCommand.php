<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Rules;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/** Renders the house files again from `config/house.php`: laravel-project-setup's `install.php --update`. */
final class UpdateRulesCommand extends Command
{
    protected $signature = 'house:update {--check : Write nothing; exit 1 when a house file differs from its render}';

    protected $description = 'Render the house rules again from config/house.php';

    public function handle(): int
    {
        $script = dirname(__DIR__, 2).'/resources/boost/skills/laravel-project-setup/scripts/install.php';
        $process = new Process([PHP_BINARY, $script, $this->laravel->basePath(), '--update', ...($this->option('check') ? ['--check'] : [])]);

        // a child killed by a signal throws, so composer never goes on to boost:update after a half-done render
        return $process->setTimeout(null)->run(fn (string $type, string $out) => $this->output->write($out));
    }
}
