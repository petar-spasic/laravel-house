<?php

namespace PetarSpasic\Kanban\Console;

use PetarSpasic\Kanban\Store\Exceptions\NotFound;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:validate')]
class ValidateCommand extends Command
{
    protected $signature = 'kanban:validate {--fix : Rewrite canonically, re-id duplicates, complete supersessions (one commit)}';

    protected $description = 'Check every board file against the schema and the cross-card rules';

    protected function perform(): int
    {
        $store = $this->gitStore() ?? throw new NotFound('validate needs the git store');
        if ($this->option('fix')) {
            $this->requireMainOrOwner('validate --fix');
            foreach ($store->fix($this->actor()) as $line) {
                $this->say("fixed {$line}");
            }
            $this->reportPending();
        }
        $snapshot = $store->snapshot();
        $errors = $store->problems($snapshot);
        foreach ($errors as $error) {
            $this->say($error);
        }
        if ($errors !== []) {
            $this->fault(count($errors).' problem(s)');

            return 2;
        }
        $this->say('ok: '.count($snapshot->cards).' cards on '.count($snapshot->boards).' boards');

        return self::SUCCESS;
    }
}
