<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\Edits;
use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:move')]
class MoveCommand extends Command
{
    protected $signature = 'kanban:move
        {id : Card id or unique prefix}
        {stage? : Target stage}
        {--board= : Move the card to another board (epic/board) instead}
        {--reason= : Why (required for dropped and for sending review back to doing)}
        {--force : Bypass the transition table (main session only; logged)}';

    protected $description = 'Move a card to another stage or board';

    protected function perform(): int
    {
        $this->requireMainOrOwner('move');
        $actor = $this->actor();
        if ($this->option('force') && ! $actor->canForce()) {
            throw new PolicyRefused('--force is for the main session only');
        }
        if ($this->option('board') !== null) {
            $found = $this->store()->card($this->argument('id'));
            Edits::assertMovable($found->data, $this->store()->snapshot()->lockedStages(), (bool) $this->option('force'));
            $card = $this->store()->relocate($found->id(), BoardRef::parse($this->option('board')), $actor);
            $this->say("{$card->id()} moved to {$card->board}");
            $this->reportPending();

            return self::SUCCESS;
        }
        if ($this->argument('stage') === null) {
            throw new Invalid('give a stage or --board');
        }
        $before = $this->store()->card($this->argument('id'));
        $card = $this->transitions()->move($before->id(), $this->argument('stage'), $actor, $this->option('reason'), (bool) $this->option('force'));
        $this->say("{$card->id()} {$before->stage()}→{$card->stage()}");
        $this->reportPending();

        return self::SUCCESS;
    }
}
