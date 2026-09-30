<?php

namespace PetarSpasic\Kanban\Console;

use PetarSpasic\Kanban\Policy\PullPolicy;
use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\Priority;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:next')]
class NextCommand extends Command
{
    protected $signature = 'kanban:next {--count=1 : How many} {--json : JSON output}';

    protected $description = 'The ready cards to start next, in pull order within capacity';

    protected function perform(): int
    {
        $this->gitStore()?->maybeSync();
        $snapshot = $this->store()->snapshot();
        $next = (new PullPolicy)->next($snapshot, max(1, (int) $this->option('count')));
        if ($this->option('json')) {
            return $this->json([
                'cards' => array_map(fn (Card $c) => $this->cardJson($c, $snapshot), $next['cards']),
                'reason' => $next['reason'],
                'capacity' => $next['capacity'],
            ]);
        }
        if ($next['cards'] === []) {
            $this->say('none: '.$next['reason']);
        }
        foreach ($next['cards'] as $card) {
            $this->say("{$card->id()} ".Priority::short($card->priority())." {$card->type()} {$card->board} {$card->title()}");
        }

        return self::SUCCESS;
    }
}
