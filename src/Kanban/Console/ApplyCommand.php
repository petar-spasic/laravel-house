<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Hooks\SubagentStop;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Applier;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Lease;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:apply')]
class ApplyCommand extends Command
{
    protected $signature = 'kanban:apply
        {id? : Only this card}
        {--all : Every staged item (the default)}';

    protected $description = 'Apply staged reports, verdicts and plans whose agent is gone; retry hook payloads left in the inbox';

    protected function perform(): int
    {
        $this->requireMainOrOwner('apply');
        (new Lease($this->paths()))->acquire($this->actor());
        $store = $this->store();
        $snapshot = $store->snapshot();
        $only = $this->argument('id') !== null ? $snapshot->resolve($this->argument('id'))->id() : null;
        $runtime = new Runtime($this->paths(), $snapshot->staleMinutes());
        $applier = new Applier($store, $this->paths(), $this->config(), $runtime);

        foreach ((new SubagentStop($this->paths(), $this->config(), $store))->retry() as $line) {
            $this->say(preg_replace('/^kanban: /', '', $line));
        }
        $count = 0;
        foreach ($runtime->stagedAll() as $item) {
            if ($only !== null && $item['card'] !== $only) {
                continue;
            }
            $type = (string) array_search($item['kind'], SubagentStop::KINDS, true);
            if (($agent = $runtime->agentFor($item['card'], $type)) !== null && $runtime->state($agent) === 'live') {
                $refused = $runtime->refusal($item['card'], $item['kind']);
                $this->say("{$item['card']}: {$item['kind']} waits for live agent {$agent['agent_id']} (applied when it stops)"
                    .($refused === null ? '' : '; last refused: '.rtrim((string) strtok($refused['reason'], "\n"), ':')));

                continue;
            }
            $line = $applier->settle($item['card'], $item['kind']);
            if ($line !== null) {
                $this->say($line);
                $count++;
            }
        }
        if ($count === 0) {
            $this->say('nothing staged'.($only !== null ? " for {$only}" : '').' to apply');
        }
        $this->reportPending();

        return self::SUCCESS;
    }
}
