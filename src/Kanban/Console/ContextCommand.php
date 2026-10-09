<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Protocol\Context;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeState;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:context')]
class ContextCommand extends Command
{
    protected $signature = 'kanban:context
        {id? : The card (default: the card whose worktree is the cwd, or the one the merge clone merges)}
        {--evaluate : Add the worker report, the diff stat and the gate commands}';

    protected $description = 'Everything an agent needs about its card: acceptance, deps, stack, last verdict, commits, dirty files';

    protected function perform(): int
    {
        $snapshot = $this->store()->snapshot();
        $context = new Context($this->paths(), $this->config());
        if ($this->paths()->insideMergeClone()) {
            $state = (new MergeState($this->paths()))->read() ?? throw new NotFound('no merge runs in the merge clone');
            $card = $snapshot->resolve($state['card']);
            if ($this->argument('id') !== null && $snapshot->resolve($this->argument('id'))->id() !== $card->id()) {
                throw new PolicyRefused("the merge clone merges {$card->id()}, not {$this->argument('id')}");
            }
            foreach ($context->mergeLines($card, $state, $snapshot) as $line) {
                $this->say($line);
            }

            return self::SUCCESS;
        }
        $card = $this->argument('id') !== null
            ? $snapshot->resolve($this->argument('id'))
            : ($context->cardAt($snapshot, $this->paths()->cwd) ?? throw new NotFound('the cwd is not the worktree of a card in doing or review: give the card id'));
        foreach ($context->lines($card, $snapshot, (bool) $this->option('evaluate')) as $line) {
            $this->say($line);
        }

        return self::SUCCESS;
    }
}
