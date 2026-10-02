<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Console\Install\Migrate;
use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:fold-boards')]
class FoldBoardsCommand extends Command
{
    protected $signature = 'kanban:fold-boards
        {--into=project/work : The one work board every card ends on}
        {--dry-run : Print the plan; write nothing}';

    protected $description = 'Fold every board into one work board and archive the decision cards (board version 1 to 2)';

    protected function perform(): int
    {
        $this->requireMainOrOwner('fold-boards');
        $dryRun = (bool) $this->option('dry-run');
        $migrate = new Migrate($this->paths(), $this->config());
        foreach ($migrate->run($dryRun) as $line) {
            $this->say($line);
        }
        if (! $dryRun && $migrate->blocked()) {
            $this->say('stopped: '.Migrate::RUNTIME.' is still there (see above); fold-boards waits for it');

            return self::FAILURE;
        }
        $store = $this->gitStore() ?? throw new NotFound('fold-boards needs the git store');
        $plan = $store->upgrade(BoardRef::parse((string) $this->option('into')), $this->actor(), $dryRun);
        foreach ($plan->warnings as $warning) {
            $this->say("warning: {$warning}");
        }
        if ($plan->isEmpty()) {
            $this->say("nothing to fold: one board, {$plan->into}, at version 2");

            return self::SUCCESS;
        }
        foreach ($plan->moves as $id => [$from, $to]) {
            $this->say("moved {$id} {$from} → {$to}");
        }
        foreach ($plan->removed as $path) {
            if (basename($path) === 'board.json') {
                $this->say('folded board '.dirname($path));
            }
        }
        foreach ($plan->archived as $id => $what) {
            $this->say("archived {$id} {$what}");
        }
        foreach ($plan->spikes as $id => $title) {
            $this->say("spike {$id} {$title} (a backlog card with its question as the block)");
        }
        foreach ($plan->questions as $id => ['title' => $title, 'cards' => $cards]) {
            $this->say("question {$id} {$title}: on ".implode(', ', $cards).(count($cards) >= 2 ? ' ('.count($cards).' cards: one piece of work? fold them into one after this)' : ''));
        }
        foreach ($plan->kept as $id => $blocked) {
            $this->say("kept block {$id}: {$blocked} (its question is in the body)");
        }
        if ($plan->noArea !== []) {
            $this->say('no area: '.implode(', ', $plan->noArea).' (promote refuses a card without an area:* label)');
        }
        if ($plan->active !== []) {
            $this->say('in doing or review: '.implode(', ', $plan->active).' (fold-boards waits for them: drain the board first)');
        }
        $this->say("startable areas: {$plan->startableAreas} of max_parallel {$plan->maxParallel}");
        $counts = count($plan->moves).' moved, '.count($plan->archived).' archived, '.count($plan->spikes).' spikes';
        $this->say($dryRun ? "would fold into {$plan->into}: {$counts}; nothing written" : "folded into {$plan->into}: {$counts}");

        return self::SUCCESS;
    }
}
