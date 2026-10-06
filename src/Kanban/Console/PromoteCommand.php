<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\Plan;
use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Policy\ReadyPolicy;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:promote')]
class PromoteCommand extends Command
{
    protected $signature = 'kanban:promote
        {ids?* : Cards to move out of the backlog: to planning, or to ready when their plan is current}
        {--auto : Send ready cards without a current plan back to planning, then fill planning from the backlog in pull order}';

    protected $description = 'Backlog → planning (or ready, with a current plan) through the ready policy (R1–R7)';

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
                $this->say("promoted {$card->id()} to {$card->stage()}");
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
     * Ready cards whose plan does not cover them (never planned, or the card or its parked work changed since) go back to
     * planning. Then backlog cards are promoted in pull order until startable ready cards and the planning cards a planner
     * holds or may take reach `ready_buffer`. A card whose dependencies are not done stays in the backlog for a later run
     * (a busy area is no reason: it is planned ahead), and every card passed over is named.
     */
    private function auto(): void
    {
        foreach ($this->store()->snapshot()->cards(fn (Card $c) => $c->stage() === 'ready' && ! Plan::current($c)) as $card) {
            $this->store()->update($card->id(), fn (array $data) => Transitions::replan($data, 'no current plan'), $this->actor());
            $this->say("replanned {$card->id()}: no current plan");
        }
        $snapshot = $this->store()->snapshot();
        $buffer = (int) $snapshot->setting('ready_buffer', 12);
        $pull = new PullPolicy;
        $count = fn (Snapshot $s) => [count($pull->candidates($s)), count($pull->plannable($s)) + count($s->cards(fn (Card $c) => $c->stage() === 'planning' && $c->atWork()))];
        [$ready, $planning] = $count($snapshot);
        $policy = new ReadyPolicy;
        foreach ($pull->sort($snapshot, $snapshot->cards(fn (Card $c) => $c->stage() === 'backlog'), 'backlog') as $card) {
            if ($ready + $planning >= $buffer) {
                break;
            }
            $refusals = $policy->refusals($card, $snapshot);
            if ($refusals !== []) {
                $this->say("skipped {$card->id()}: ".implode('; ', $refusals));

                continue;
            }
            $asPlanning = new Card(['stage' => 'planning'] + $card->data, $card->board, $card->path, $card->rev);
            if (($why = $pull->waiting($snapshot->withCard($asPlanning))[$card->id()] ?? null) !== null) {
                $this->say("skipped {$card->id()}: {$why}");

                continue;
            }
            $promoted = $this->transitions()->promote($card->id(), $this->actor());
            $this->say("promoted {$card->id()} to {$promoted->stage()}");
            $snapshot = $this->store()->snapshot();
            [$ready, $planning] = $count($snapshot);
        }
        $this->say("ready {$ready} startable, planning {$planning}: ".($ready + $planning)."/{$buffer}");
    }
}
