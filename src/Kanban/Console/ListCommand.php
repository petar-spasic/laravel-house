<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:list')]
class ListCommand extends Command
{
    protected $signature = 'kanban:list
        {--board= : Only this board (epic/board)}
        {--stage= : Only this stage}
        {--type= : Only this card type}
        {--label= : Only cards with this label}
        {--all : Every stage}
        {--json : JSON output}';

    protected $description = 'List cards (default: ready, doing and review, plus blocked anywhere)';

    protected function perform(): int
    {
        $snapshot = $this->store()->snapshot();
        $board = $this->option('board') ? BoardRef::parse($this->option('board')) : null;
        $stage = $this->option('stage');
        $explicit = $this->option('all') || $stage || $this->option('type') || $this->option('label');
        $cards = $snapshot->cards(fn (Card $c) => ($board === null || $c->board->equals($board))
            && ($stage === null || $c->stage() === $stage)
            && ($this->option('type') === null || $c->type() === $this->option('type'))
            && ($this->option('label') === null || in_array($this->option('label'), $c->labels(), true))
            && ($explicit || in_array($c->stage(), ['ready', 'doing', 'review'], true) || $c->blocked() !== null));

        $order = array_flip(['doing', 'review', 'ready', 'backlog', 'proposed', 'decided', 'done', 'superseded', 'dropped']);
        $sorted = (new PullPolicy)->sort($snapshot, $cards, 'ready');
        $position = array_flip(array_map(fn (Card $c) => $c->id(), $sorted));
        usort($cards, fn (Card $a, Card $b) => [$order[$a->stage()] ?? 99, $position[$a->id()]] <=> [$order[$b->stage()] ?? 99, $position[$b->id()]]);

        if ($this->option('json')) {
            return $this->json(array_map(fn (Card $c) => $this->cardJson($c, $snapshot), $cards));
        }
        foreach ($cards as $card) {
            $this->say($this->cardLine($card, $snapshot));
        }
        if ($cards === []) {
            $this->say('no cards');
        }

        return self::SUCCESS;
    }
}
