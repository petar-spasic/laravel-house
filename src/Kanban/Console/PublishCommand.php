<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\MainPush;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:publish')]
class PublishCommand extends Command
{
    protected $signature = 'kanban:publish';

    protected $description = 'Sync the board, then push main (merging origin/main when it moved)';

    protected function perform(): int
    {
        SyncCommand::report($this->store()->sync(), $this->say(...));
        $remote = (string) $this->setting('remote', 'origin');
        $branch = (string) $this->setting('main_branch', 'main');
        $push = new MainPush(new Git($this->paths()->main), $remote, $branch);
        if (! $push->hasRemote()) {
            $this->say("{$branch}: no remote {$remote}");

            return self::SUCCESS;
        }
        $push->push($this->say(...));

        return self::SUCCESS;
    }
}
