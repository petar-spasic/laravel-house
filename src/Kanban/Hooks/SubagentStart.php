<?php

namespace PetarSpasic\Kanban\Hooks;

use PetarSpasic\Kanban\Protocol\Runtime;
use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\Store;
use PetarSpasic\Kanban\Support\Clock;
use PetarSpasic\Kanban\Support\Paths;

/**
 * SubagentStart: records kanban agents in the runtime (a resume keeps the binding) and gives every
 * subagent except Explore and Plan a short additionalContext with the board rules and the cards in doing.
 */
final class SubagentStart
{
    private const SILENT = ['Explore', 'Plan'];

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public function __construct(
        private readonly Paths $paths,
        private readonly array $config,
        private readonly Store $store,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{stdout: string, stderr: string, exit: int}
     */
    public function handle(array $payload): array
    {
        if (! $this->paths->hasBoard()) {
            return ['stdout' => '', 'stderr' => '', 'exit' => 0];
        }
        $type = is_string($payload['agent_type'] ?? null) ? $payload['agent_type'] : '';
        $agentId = (string) ($payload['agent_id'] ?? '');
        $snapshot = $this->store->snapshot();
        $runtime = new Runtime($this->paths, $snapshot->staleMinutes());

        if (in_array($type, [SubagentStop::WORKER, SubagentStop::EVALUATOR], true) && Runtime::validAgentId($agentId)) {
            $agent = $runtime->agent($agentId) ?? ['agent_id' => $agentId, 'agent_type' => $type, 'card' => null, 'worktree' => null,
                'bound_at' => null, 'stop_blocks' => 0];
            unset($agent['stop_reason']);
            $runtime->saveAgent(['started_at' => Clock::now(), 'stopped_at' => null] + $agent);
        }
        if (in_array($type, self::SILENT, true)) {
            return ['stdout' => '', 'stderr' => '', 'exit' => 0];
        }

        $doing = array_map(fn (Card $c) => $c->id().' '.($c->work()['worktree'] ?? '(no worktree)'),
            $snapshot->cards(fn (Card $c) => $c->stage() === 'doing' && ! $c->isDecision()));
        $context = 'Kanban board: '.$this->paths->board().' (branch kanban). Read: `vendor/bin/kanban status|list|show ID|context`; '
            .'change it only via vendor/bin/kanban, never edit it. Git: add/commit only in your own card worktree; '
            .'push/pull/fetch/stash/reset/checkout/switch/merge/rebase/worktree are the main session\'s: do not run them. '
            .'Doing: '.($doing === [] ? 'none' : implode(', ', $doing)).'.';
        $json = ['hookSpecificOutput' => ['hookEventName' => 'SubagentStart', 'additionalContext' => $context]];

        return ['stdout' => json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", 'stderr' => '', 'exit' => 0];
    }
}
