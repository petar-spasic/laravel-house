<?php

namespace PetarSpasic\Kanban\Console;

use PetarSpasic\Kanban\Store\SyncResult;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:sync')]
class SyncCommand extends Command
{
    protected $signature = 'kanban:sync {--background : Debounced run after writes when sync=on}';

    protected $description = 'Pull (rebase with the merge driver) and push the kanban branch';

    protected function perform(): int
    {
        if ($this->option('background')) {
            $this->gitStore()?->backgroundSync();

            return self::SUCCESS;
        }
        self::report($this->store()->sync(), $this->say(...));
        $this->reportPending();

        return self::SUCCESS;
    }

    /** @param  callable(string): void  $say */
    public static function report(SyncResult $result, callable $say): void
    {
        foreach ($result->renamed as $old => $new) {
            $say("renamed {$old} → {$new} (id taken on the remote)");
        }
        $say(match ($result->status) {
            'no-remote' => 'sync: no remote configured',
            'pushed' => "sync: pushed {$result->pushed} commit(s)".($result->pulled > 0 ? ", pulled {$result->pulled}" : ''),
            'pulled' => "sync: pulled {$result->pulled} commit(s)",
            default => 'sync: up to date',
        });
        foreach ($result->warnings as $warning) {
            $say("warning: {$warning}");
        }
    }
}
