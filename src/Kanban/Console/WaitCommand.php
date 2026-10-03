<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:wait')]
class WaitCommand extends Command
{
    /** Seconds between looks at the runtime files. */
    private const POLL = 0.25;

    protected $signature = 'kanban:wait
        {ids?* : The cards to wait for (default: every card with a live agent)}
        {--timeout=90 : Seconds before giving up with exit 75}';

    protected $description = 'Block until a card\'s agent has stopped (its report or verdict applied) or its stop was refused';

    protected function perform(): int
    {
        $this->requireMainOrOwner('wait');
        $timeout = (int) $this->option('timeout');
        if ($timeout < 1) {
            throw new Invalid('--timeout must be at least 1 second');
        }
        $snapshot = $this->store()->snapshot();
        $ids = array_map(fn (string $id) => $this->store()->card($id)->id(), (array) $this->argument('ids'));
        if ($ids === []) {
            $runtime = new Runtime($this->paths(), $snapshot->staleMinutes());
            $ids = array_values(array_unique(array_map(fn (array $a) => (string) $a['card'], array_filter($runtime->agents(),
                fn (array $a) => is_string($a['card'] ?? null) && $runtime->state($a) === 'live'))));
            if ($ids === []) {
                $this->say('no live agents');

                return self::SUCCESS;
            }
        }

        $deadline = microtime(true) + $timeout;
        while (true) {
            $settled = [];
            foreach ($ids as $id) {
                if (($outcome = $this->outcome($id, $snapshot->staleMinutes())) !== null) {
                    $settled[$id] = $outcome;
                }
            }
            if ($settled !== []) {
                $snapshot = $this->store()->snapshot();
                foreach ($settled as $id => $outcome) {
                    $card = $snapshot->card($id);
                    $this->say(($card instanceof Card ? $this->cardLine($card, $snapshot) : $id).' — '.$outcome);
                }

                return self::SUCCESS;
            }
            if (microtime(true) >= $deadline) {
                $this->say('still running after '.$timeout.' s: '.implode(', ', $ids).'; run `wait` again');

                return 75;
            }
            usleep((int) (self::POLL * 1_000_000));
        }
    }

    /** What settled the card's agent, or null while it still runs. */
    private function outcome(string $id, int $staleMinutes): ?string
    {
        $runtime = new Runtime($this->paths(), $staleMinutes);
        $agent = $runtime->agentFor($id);
        if ($agent === null) {
            return 'no agent';
        }
        $state = $runtime->state($agent);
        if ($state !== 'live') {
            return $state === 'stopped' ? 'agent stopped' : 'agent stale (no heartbeat)';
        }
        // a refusal no tool call has followed is this stop's: the record was written then, and any later call touches it
        $refused = $agent['stop_refused'] ?? null;
        if (is_array($refused) && (int) ($refused['at'] ?? 0) >= (int) $agent['beat']) {
            return 'stop refused, the agent works on: '.($refused['reason'] ?? '').'; wait for its next hand-back';
        }

        return null;
    }
}
