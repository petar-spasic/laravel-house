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

    private function auto(): void
    {
        $snapshot = $this->store()->snapshot();
        $buffer = (int) $snapshot->setting('ready_buffer', 12);
        $ready = count($snapshot->cards(fn (Card $c) => $c->stage() === 'ready'));
        $policy = new ReadyPolicy;
        $candidates = (new PullPolicy)->sort($snapshot, $snapshot->cards(fn (Card $c) => $c->stage() === 'backlog' && ! $c->isDecision()), 'backlog');
        foreach ($candidates as $card) {
            if ($ready >= $buffer) {
                break;
            }
            if ($policy->refusals($card, $snapshot) !== []) {
                continue;
            }
            $this->transitions()->promote($card->id(), $this->actor());
            $this->say("promoted {$card->id()}");
            $ready++;
        }
        $this->say("ready {$ready}/{$buffer}");
    }
}
