<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Priority;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:next')]
class NextCommand extends Command
{
    protected $signature = 'kanban:next {--count=1 : How many} {--json : JSON output}';

    protected $description = 'The ready cards to start next, in pull order within capacity (-v: and why the others wait)';

    protected function perform(): int
    {
        $this->gitStore()?->maybeSync();
        $snapshot = $this->store()->snapshot();
        $pull = new PullPolicy;
        $next = $pull->next($snapshot, max(1, (int) $this->option('count')));
        $skipped = $pull->skipped($snapshot);
        if ($this->option('json')) {
            return $this->json([
                'cards' => array_map(fn (Card $c) => $this->cardJson($c, $snapshot), $next['cards']),
                'reason' => $next['reason'],
                'capacity' => $next['capacity'],
                'skipped' => $skipped,
            ]);
        }
        if ($next['cards'] === []) {
            $this->say('none: '.$next['reason']);
        }
        foreach ($next['cards'] as $card) {
            $this->say("{$card->id()} ".Priority::short($card->priority())." {$card->type()} {$card->board} {$card->title()}");
        }
        if ($next['cards'] === [] || $this->output->isVerbose()) {
            foreach ($skipped as $id => $reason) {
                $this->say("skipped {$id} {$reason}");
            }
        }

        return self::SUCCESS;
    }
}
