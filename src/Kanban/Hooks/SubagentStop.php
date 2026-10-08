<?php

namespace PetarSpasic\LaravelHouse\Kanban\Hooks;

use PetarSpasic\LaravelHouse\Kanban\Protocol\Applier;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Context;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Runtime;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LockTimeout;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\StaleReport;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;
use Throwable;

/**
 * SubagentStop for kanban-worker / kanban-evaluator / kanban-planner: refuses the stop until a report (verdict, plan) is
 * staged and the card supports it, then applies it and unbinds the agent.
 */
final class SubagentStop
{
    public const WORKER = 'kanban-worker';

    public const EVALUATOR = 'kanban-evaluator';

    public const PLANNER = 'kanban-planner';

    /** What each kanban agent stages. */
    public const KINDS = [self::WORKER => 'report', self::EVALUATOR => 'verdict', self::PLANNER => 'plan'];

    public const MAX_BLOCKS = 3;

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
        $type = is_string($payload['agent_type'] ?? null) ? $payload['agent_type'] : '';
        $agentId = self::agentId($payload);
        if (! isset(self::KINDS[$type]) || ! Runtime::validAgentId($agentId) || ! $this->paths->hasBoard()) {
            return self::done();
        }
        $snapshot = $this->store->snapshot();
        $runtime = $this->runtime($snapshot);
        $agent = $runtime->agent($agentId) ?? ['agent_id' => $agentId, 'agent_type' => $type, 'stop_blocks' => 0];
        $cardId = $this->cardOf($agent, $payload, $snapshot);
        if ($cardId === null) {
            return self::done("kanban: {$agentId} ({$type}) is bound to no card; nothing to apply");
        }
        $worker = $type === self::WORKER;
        $kind = self::KINDS[$type];
        $applier = new Applier($this->store, $this->paths, $this->config, $runtime);
        $since = max((string) ($agent['started_at'] ?? ''), (string) ($agent['bound_at'] ?? ''));
        $staged = $runtime->staged($cardId, $kind);
        if ($staged !== null && (string) ($staged['staged_at'] ?? '') < $since) {
            $staged = null;
        }

        if ($staged === null) {
            foreach ($runtime->applied($cardId, $kind) as $applied) {
                if ((string) ($applied['staged_at'] ?? '') >= $since) {
                    $this->unbind($runtime, $agent, $cardId);

                    return self::done();
                }
            }

            // a card that left this agent's hands (stopped, re-claimed, moved on another machine) has nothing to report on, and a block written now would land on someone else's card
            $card = $snapshot->resolve($cardId);
            if (($stale = match ($kind) {
                'report' => $applier->stale($card), 'plan' => $applier->stalePlan($card), default => null
            }) !== null) {
                $this->unbind($runtime, $agent, $cardId);

                return self::done("kanban: {$cardId}: no {$kind} applied: {$stale}");
            }

            // an evaluator whose card left review (finished on another approval, sent back, stopped) has nothing to judge
            if ($kind === 'verdict' && $card->stage() !== 'review') {
                $this->unbind($runtime, $agent, $cardId);

                return self::done("kanban: {$cardId}: no verdict needed: the card is {$card->stage()}");
            }

            return $this->block($runtime, $agent, $cardId, match ($kind) {
                'report' => "No report staged for {$cardId}. Run: vendor/bin/kanban report {$cardId} --status=review|blocked [--tick=N …] --summary-file=- <<'EOF' … EOF (blocked needs --reason=\"…\"). If vendor/bin/kanban cannot reach the board, end your last message with why: after ".self::MAX_BLOCKS.' refusals the stop goes through and the card is blocked',
                'plan' => "No plan staged for {$cardId}. Write it to .tmp/plan.md, then run: vendor/bin/kanban plan {$cardId} --plan-file=.tmp/plan.md (blocked: --status=blocked --reason=\"…\", or an Open question in --question-file). If vendor/bin/kanban cannot reach the board, end your last message with why: after ".self::MAX_BLOCKS.' refusals the stop goes through and the card is blocked',
                default => "No verdict staged for {$cardId}. Run: vendor/bin/kanban verdict {$cardId} approve|reject --check=N:pass|fail:\"evidence\" … (one --check per criterion)",
            });
        }

        $card = $snapshot->resolve($cardId);
        if (($stale = match ($kind) {
            'report' => $applier->stale($card), 'plan' => $applier->stalePlan($card), default => null
        }) !== null) {
            $this->unbind($runtime, $agent, $cardId);

            return self::done("kanban: {$kind} for {$cardId} stays staged: {$stale}");
        }
        if ($worker && ($refusal = $applier->refusal($snapshot->resolve($cardId), $staged, $staged['status'] === 'blocked')) !== null) {
            $runtime->noteRefusal($cardId, $kind, $refusal);

            return $this->block($runtime, $agent, $cardId, "Report for {$cardId} not applied. {$refusal}\nFix it, commit, run `vendor/bin/kanban report` again (it runs the gates), then finish again.");
        }
        try {
            $line = match ($kind) {
                'report' => $applier->report($staged), 'plan' => $applier->plan($staged), default => $applier->verdict($staged)
            };
        } catch (StaleReport $e) {
            $this->unbind($runtime, $agent, $cardId);

            return self::done("kanban: {$kind} for {$cardId} stays staged: ".$e->getMessage().'; `vendor/bin/kanban apply '.$cardId.'` applies it once the card is this machine\'s again');
        } catch (PolicyRefused $e) {
            $runtime->noteRefusal($cardId, $kind, $e->getMessage());

            return $this->block($runtime, $agent, $cardId, ucfirst($kind)." for {$cardId} not applied: ".$e->getMessage());
        }
        $this->unbind($runtime, $agent, $cardId);

        return self::done("kanban: {$line}");
    }

    /**
     * Replays SubagentStop payloads left in the inbox by a failed hook: whatever the agent staged is applied.
     *
     * @return list<string>
     */
    public function retry(?string $exclude = null): array
    {
        if (! $this->paths->hasBoard()) {
            return [];
        }
        $runtime = $this->runtime($this->store->snapshot());
        $lines = [];
        foreach ($runtime->inboxLeftovers() as $item) {
            if ($item['file'] === $exclude) {
                $item['lock']->release();

                continue;
            }
            // one that fails stays for the next retry, and the others go on; a busy board lock ends the retry, which every
            // item would wait on
            try {
                if ($item['event'] === 'subagent-stop') {
                    $lines = array_merge($lines, $this->settle($item['payload'], $runtime));
                }
            } catch (LockTimeout $e) {
                $item['lock']->release();

                throw $e;
            } catch (Throwable $e) {
                $item['lock']->release();
                $lines[] = 'kanban: inbox '.basename($item['file']).' not retried: '.$e->getMessage();

                continue;
            }
            @unlink($item['file']);
            $item['lock']->release();
            $lines[] = 'kanban: inbox '.basename($item['file']).' retried';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function settle(array $payload, Runtime $runtime): array
    {
        $type = is_string($payload['agent_type'] ?? null) ? $payload['agent_type'] : '';
        $agentId = self::agentId($payload);
        if (! isset(self::KINDS[$type]) || ! Runtime::validAgentId($agentId)) {
            return [];
        }
        $agent = $runtime->agent($agentId) ?? ['agent_id' => $agentId, 'agent_type' => $type, 'stop_blocks' => 0];
        $cardId = $this->cardOf($agent, $payload, $this->store->snapshot());
        if ($cardId === null) {
            return [];
        }
        $line = (new Applier($this->store, $this->paths, $this->config, $runtime))->settle($cardId, self::KINDS[$type]);
        $this->unbind($runtime, $agent, $cardId);

        return $line === null ? [] : ["kanban: {$line}"];
    }

    /**
     * Keeps a failure of this hook beside what the agent staged, so `status` and `apply` can say why it was not applied.
     *
     * @param  array<string, mixed>  $payload
     */
    public function failed(array $payload, string $message): void
    {
        $type = is_string($payload['agent_type'] ?? null) ? $payload['agent_type'] : '';
        $agentId = self::agentId($payload);
        if (! isset(self::KINDS[$type]) || ! Runtime::validAgentId($agentId)) {
            return;
        }
        $runtime = new Runtime($this->paths);
        $card = $runtime->agent($agentId)['card'] ?? null;
        if (is_string($card) && $card !== '') {
            $runtime->noteRefusal($card, self::KINDS[$type], "hook failed: {$message}");
        }
    }

    /**
     * @param  array<string, mixed>  $agent
     * @param  array<string, mixed>  $payload
     */
    private function cardOf(array $agent, array $payload, Snapshot $snapshot): ?string
    {
        if (is_string($agent['card'] ?? null) && $agent['card'] !== '') {
            return $agent['card'];
        }
        $cwd = is_string($payload['cwd'] ?? null) ? $payload['cwd'] : '';

        return $cwd === '' ? null : (new Context($this->paths, $this->config))->cardAt($snapshot, $cwd)?->id();
    }

    /**
     * Refuses the stop, or after MAX_BLOCKS refusals lets it through (a worker's card becomes blocked).
     *
     * @param  array<string, mixed>  $agent
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function block(Runtime $runtime, array $agent, string $cardId, string $reason): array
    {
        $blocks = (int) ($agent['stop_blocks'] ?? 0);
        if ($blocks >= self::MAX_BLOCKS) {
            // a blocked card is one `kanban run` launches no agent for again
            if (isset(self::KINDS[(string) ($agent['agent_type'] ?? '')])) {
                $role = str_replace('kanban-', '', (string) $agent['agent_type']);
                $why = match (true) {
                    str_starts_with($reason, 'No report') => 'worker stopped without report',
                    str_starts_with($reason, 'No verdict') => 'evaluator stopped without verdict',
                    str_starts_with($reason, 'No plan') => 'planner stopped without a plan',
                    default => "{$role} stopped: ".strtok($reason, "\n"),
                };
                try {
                    $this->store->update($cardId, function (array $data) use ($why) {
                        $data['blocked'] = mb_substr($why, 0, 500);

                        return $data;
                    }, new Actor('hook'));
                } catch (NotFound) {
                }
            }
            $this->unbind($runtime, $agent, $cardId);

            return self::done("kanban: {$cardId}: stop allowed after ".self::MAX_BLOCKS.' refusals');
        }
        $agent['stop_blocks'] = $blocks + 1;
        $agent['stop_refused'] = ['at' => microtime(true), 'reason' => mb_strimwidth((string) strtok($reason, "\n"), 0, 200, '…')];
        $agent['card'] ??= $cardId;
        unset($agent['beat']);
        $runtime->saveAgent($agent);

        return ['stdout' => json_encode(['decision' => 'block', 'reason' => $reason], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", 'stderr' => '', 'exit' => 0];
    }

    /** @param  array<string, mixed>  $agent */
    private function unbind(Runtime $runtime, array $agent, string $cardId): void
    {
        unset($agent['beat']);
        $agent['card'] ??= $cardId;
        $agent['stopped_at'] = Clock::now();
        $agent['stop_blocks'] = 0;
        $runtime->saveAgent($agent);
    }

    private function runtime(Snapshot $snapshot): Runtime
    {
        return new Runtime($this->paths, $snapshot->staleMinutes());
    }

    /** @return array{stdout: string, stderr: string, exit: int} */
    /**
     * The subagent's id, or for a headless card session (`kanban run`, which stops with Stop) its session id.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function agentId(array $payload): string
    {
        return (string) ($payload['agent_id'] ?? '') ?: (string) ($payload['session_id'] ?? '');
    }

    private static function done(string $note = ''): array
    {
        return ['stdout' => '', 'stderr' => $note === '' ? '' : $note."\n", 'exit' => 0];
    }
}
