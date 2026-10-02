<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Store\Git\MergeDriver;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:merge-driver')]
class MergeDriverCommand extends Command
{
    protected $signature = 'kanban:merge-driver
        {ancestor : %O}
        {current : %A (result is written here)}
        {other : %B}
        {path : %P}';

    protected $description = 'git merge driver for board JSON (configured by attach)';

    protected function perform(): int
    {
        return MergeDriver::run($this->argument('ancestor'), $this->argument('current'), $this->argument('other'), $this->argument('path'));
    }
}
