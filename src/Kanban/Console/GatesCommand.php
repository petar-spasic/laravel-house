<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Protocol\Context;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Gates;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:gates')]
class GatesCommand extends Command
{
    protected $signature = 'kanban:gates
        {id? : The card (default: the card whose worktree is the cwd)}';

    protected $description = "Run main's gates in the card's worktree, every one of them, and say which fail (stages nothing)";

    protected function perform(): int
    {
        $snapshot = $this->store()->snapshot();
        $context = new Context($this->paths(), $this->config());
        $card = $this->argument('id') !== null
            ? $snapshot->resolve($this->argument('id'))
            : ($context->cardAt($snapshot, $this->paths()->cwd) ?? throw new NotFound('the cwd is not the worktree of a card in doing or review: run it from the card\'s worktree'));
        $worktree = $context->requireInside($card, $this->paths()->cwd, 'gates');
        $results = (new Gates($this->config()))->results($worktree);
        if ($results === []) {
            $this->say('gates: none');
        }
        foreach ($results as $result) {
            $this->say(($result['ok'] ? 'pass ' : 'fail ')."{$result['run']} ({$result['why']})");
            foreach ($result['tail'] === '' ? [] : explode("\n", $result['tail']) as $line) {
                $this->say('  '.$line);
            }
        }

        return in_array(false, array_column($results, 'ok'), true) ? 1 : self::SUCCESS;
    }
}
