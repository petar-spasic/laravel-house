<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\Plan;
use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Policy\ReadyPolicy;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\KanbanException;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LockTimeout;
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
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $card = $transitions->promote($id, $this->actor());
                $this->say("promoted {$card->id()} to {$card->stage()}");
            } catch (PolicyRefused $e) {
                $refused++;
                $this->say($e->getMessage());
            } catch (LockTimeout $e) {
                throw $e;
            } catch (KanbanException $e) {
                $failed = $failed ?: $e->exitCode();
                $this->fault("skipped {$id}: {$e->getMessage()}");
            }
        }
        if ($this->option('auto')) {
            $failed = $this->auto() ?: $failed;
        }
        $this->reportPending();

        return $failed ?: ($refused > 0 ? 3 : self::SUCCESS);
    }

    /**
     * Ready cards whose plan does not cover them (never planned, or the card or its parked work changed since) go back to
     * planning. Then backlog cards are promoted in pull order until the cards waiting to start (ready, a current plan) and
     * those in planning a planner holds or may take reach `ready_buffer`, one card ahead per area: an area runs one card at
     * a time, so a second would wait on a plan going stale. A card whose dependencies are not done stays in the backlog for a
     * later run, and every card passed over is named. A card that cannot be written is named on stderr and the rest go on;
     * the exit code of the first such failure is returned, 0 when there was none. A busy board lock ends the command: every
     * card would wait on it.
     */
    private function auto(): int
    {
        $failed = 0;
        $skip = function (Card $card, KanbanException $e) use (&$failed) {
            $failed = $failed ?: $e->exitCode();
            $this->fault("skipped {$card->id()}: {$e->getMessage()}");
        };
        foreach ($this->store()->snapshot()->cards(fn (Card $c) => $c->stage() === 'ready' && ! Plan::current($c)) as $card) {
            try {
                $this->store()->update($card->id(), fn (array $data) => Transitions::replan($data, 'no current plan'), $this->actor());
                $this->say("replanned {$card->id()}: no current plan");
            } catch (LockTimeout $e) {
                throw $e;
            } catch (KanbanException $e) {
                $skip($card, $e);
            }
        }
        $snapshot = $this->store()->snapshot();
        $buffer = (int) $snapshot->setting('ready_buffer', 12);
        $pull = new PullPolicy;
        $ahead = fn (Snapshot $s) => [...$pull->queued($s), ...$s->cards(fn (Card $c) => $c->stage() === 'planning' && $c->atWork()), ...$pull->plannable($s)];
        $cards = $ahead($snapshot);
        $policy = new ReadyPolicy;
        foreach ($pull->sort($snapshot, $snapshot->cards(fn (Card $c) => $c->stage() === 'backlog'), 'backlog') as $card) {
            if (count($cards) >= $buffer) {
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
            $next = array_values(array_filter($cards, fn (Card $c) => array_intersect($c->areas(), $card->areas()) !== []))[0] ?? null;
            if ($next !== null) {
                $this->say("skipped {$card->id()}: ".implode(', ', array_intersect($next->areas(), $card->areas()))." takes {$next->id()} next ({$next->stage()})");

                continue;
            }
            try {
                $promoted = $this->transitions()->promote($card->id(), $this->actor());
                $this->say("promoted {$card->id()} to {$promoted->stage()}");
            } catch (LockTimeout $e) {
                throw $e;
            } catch (KanbanException $e) {
                $skip($card, $e);
            }
            $snapshot = $this->store()->snapshot();
            $cards = $ahead($snapshot);
        }
        $ready = count(array_filter($cards, fn (Card $c) => $c->stage() === 'ready'));
        $this->say("ready {$ready}, planning ".(count($cards) - $ready).': '.count($cards)."/{$buffer}");

        return $failed;
    }
}
