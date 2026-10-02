<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Policy\ReadyPolicy;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:promote')]
class PromoteCommand extends Command
{
    protected $signature = 'kanban:promote
        {ids?* : Cards to move backlog → ready}
        {--auto : Fill the ready buffer from the backlog in pull order}';

    protected $description = 'Backlog → ready through the ready policy (R1–R7)';

    protected function perform(): int
    {
        $this->requireMainOrOwner('promote');
        $ids = $this->argument('ids');
        if ($ids === [] && ! $this->option('auto')) {
            throw new Invalid('give card ids or --auto');
        }
        $transitions = $this->transitions();
        $refused = 0;
        foreach ($ids as $id) {
            try {
                $card = $transitions->promote($id, $this->actor());
                $this->say("promoted {$card->id()}");
            } catch (PolicyRefused $e) {
                $refused++;
                $this->say($e->getMessage());
            }
        }
        if ($this->option('auto')) {
            $this->auto();
        }
        $this->reportPending();

        return $refused > 0 ? 3 : self::SUCCESS;
    }

    /**
     * Promotes backlog cards in pull order until `ready_buffer` cards in ready are startable. A card that could not start
     * yet (dependencies not done, its area busy) stays in the backlog for a later run, and every card passed over is named.
     */
    private function auto(): void
    {
        $snapshot = $this->store()->snapshot();
        $buffer = (int) $snapshot->setting('ready_buffer', 12);
        $pull = new PullPolicy;
        $ready = count($pull->candidates($snapshot));
        $policy = new ReadyPolicy;
        foreach ($pull->sort($snapshot, $snapshot->cards(fn (Card $c) => $c->stage() === 'backlog'), 'backlog') as $card) {
            if ($ready >= $buffer) {
                break;
            }
            $refusals = $policy->refusals($card, $snapshot);
            if ($refusals !== []) {
                $this->say("skipped {$card->id()}: ".implode('; ', $refusals));

                continue;
            }
            $asReady = new Card(['stage' => 'ready'] + $card->data, $card->board, $card->path, $card->rev);
            if (($why = $pull->skipped($snapshot->withCard($asReady))[$card->id()] ?? null) !== null) {
                $this->say("skipped {$card->id()}: {$why}");

                continue;
            }
            $this->transitions()->promote($card->id(), $this->actor());
            $this->say("promoted {$card->id()}");
            $snapshot = $this->store()->snapshot();
            $ready = count($pull->candidates($snapshot));
        }
        $this->say("ready {$ready}/{$buffer} startable");
    }
}
