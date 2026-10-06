<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Claim;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\LaravelHouse\Kanban\Store\Rev;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Stage;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\Clock;

/**
 * Stage changes. `check()` is the table the Store enforces on every write; the instance methods are the only
 * way to reach the moves that `move` refuses (start, apply, send back, finish, stop, plan).
 */
final class Transitions
{
    /**
     * "from>to" => vias that may perform it. `move` covers the CLI/UI; `promote` the ready gate, which sends a card to planning
     * unless its plan is current; `plan` a finished plan; `replan` a ready card whose plan no longer covers it.
     */
    private const ALLOWED = [
        'backlog>planning' => ['move', 'promote'],
        'backlog>ready' => ['promote'],
        'planning>ready' => ['plan'],
        'planning>backlog' => ['move', 'stop'],
        'planning>dropped' => ['move', 'stop', 'fold'],
        'ready>planning' => ['move', 'replan'],
        'ready>backlog' => ['move'],
        'ready>doing' => ['start'],
        'doing>review' => ['apply'],
        'review>doing' => ['reject', 'refresh', 'move'],
        'review>done' => ['finish'],
        'doing>ready' => ['stop'], 'doing>backlog' => ['stop'], 'doing>dropped' => ['stop'],
        'review>ready' => ['stop'], 'review>backlog' => ['stop'], 'review>dropped' => ['stop'],
        'backlog>dropped' => ['move', 'fold'], 'ready>dropped' => ['move', 'fold'],
        'dropped>backlog' => ['move'],
        // an open question of a version 1 board becomes a backlog spike
        'proposed>backlog' => ['fold-boards'],
    ];

    private const HINTS = [
        'backlog>ready' => 'use `kanban promote ID`: a card without a current plan goes to planning first',
        'planning>ready' => 'a plan moves it: its planner\'s (`kanban stop ID --to=ready` once staged and applied), or `kanban plan ID --plan-file=…`',
        'ready>doing' => 'use `kanban start ID`',
        'doing>review' => 'a worker report moves it (`kanban apply ID`)',
        'review>done' => 'use `kanban finish ID`',
    ];

    public function __construct(
        private readonly Store $store,
        private readonly ReadyPolicy $ready = new ReadyPolicy,
        private readonly PullPolicy $pull = new PullPolicy,
    ) {}

    /**
     * Stages a card in $from may be moved to with `move`.
     *
     * @return list<string>
     */
    public static function moveTargets(string $from): array
    {
        $targets = [];
        foreach (self::ALLOWED as $edge => $vias) {
            [$edgeFrom, $to] = explode('>', $edge);
            if ($edgeFrom === $from && in_array('move', $vias, true)) {
                $targets[] = $to;
            }
        }

        return $targets;
    }

    /** Throws PolicyRefused unless $via may move a card from $from to $to. */
    public static function check(string $from, string $to, string $via, Actor $by, bool $forced = false): void
    {
        if (! in_array($to, Stage::WORK, true)) {
            throw new PolicyRefused("'{$to}' is not a stage (".implode(', ', Stage::WORK).')');
        }
        if ($from === $to) {
            return;
        }
        if ($forced) {
            if (! $by->canForce()) {
                throw new PolicyRefused('--force is for the main session only');
            }

            return;
        }
        $allowed = self::ALLOWED["{$from}>{$to}"] ?? [];
        if (! in_array($via, $allowed, true)) {
            $hint = self::HINTS["{$from}>{$to}"]
                ?? (Stage::isActive($from) ? "use `kanban stop ID --to={$to}`" : null)
                ?? ($allowed === [] ? "{$from} → {$to} is not a transition" : 'allowed through '.implode(', ', $allowed));
            throw new PolicyRefused("refused {$from} → {$to}: {$hint}");
        }
    }

    /**
     * Pure stage change on card data: coupled fields follow, a `stage` log entry is appended.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stage(array $data, string $to, string $via, ?string $reason = null, bool $force = false): array
    {
        $from = $data['stage'];
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        if ($to === 'dropped' && $reason === null) {
            throw new PolicyRefused('dropping needs a reason (--reason)');
        }
        $claimed = Stage::isActive($from) || ($from === 'planning' && ($data['claim'] ?? null) !== null);
        $data['stage'] = $to;
        if ($claimed && ! Stage::isActive($to) && $to !== $from) {
            $data['claim'] = null;
            $data['work'] = $to === 'done'
                ? array_intersect_key($data['work'] ?? [], array_flip(['branch', 'base', 'merge', 'started', 'finished']))
                : null;
        }
        // git keeps it: a finished card's plan is no longer worth reading on every load
        if (in_array($to, ['done', 'dropped'], true)) {
            unset($data['plan']);
        }
        $entry = ['event' => 'stage', 'from' => $from, 'to' => $to];
        if ($via !== 'move') {
            $entry['via'] = $via;
        }
        if ($reason !== null) {
            $entry['reason'] = $reason;
        }
        if ($force) {
            $entry['forced'] = true;
        }
        $data['log'][] = $entry;

        return $data;
    }

    /**
     * Owner/main move from the CLI or UI. Out of backlog runs the ready policy (`promote`, which picks planning or ready); review
     * → doing needs a note; a card a planner holds is left to `stop`.
     * $expected: the rev the caller read the card at (`Changed` when it moved on since); default the current one.
     */
    public function move(string $id, string $to, Actor $by, ?string $reason = null, bool $force = false, ?Rev $expected = null): Card
    {
        $card = $this->store->card($id);
        if (! $force && in_array($to, ['planning', 'ready'], true) && $card->stage() === 'backlog') {
            return $this->promote($card->id(), $by, $expected);
        }
        if (! $force && $card->stage() === 'planning' && $card->claim() !== null && $to !== 'planning') {
            throw new PolicyRefused("{$card->id()} is being planned: `kanban stop {$card->id()} --to=backlog|dropped` takes its planner off it");
        }
        if ($card->stage() === 'review' && $to === 'doing') {
            if (! $force && ($reason === null || trim($reason) === '')) {
                throw new PolicyRefused('sending back to doing needs a note (--reason)');
            }

            return $this->sendBack($card->id(), 'move', $by, $reason, $expected ?? $card->rev, $force);
        }

        return $this->store->update($card->id(), fn (array $data) => self::stage($data, $to, 'move', $reason, $force), $by, $expected ?? $card->rev);
    }

    /**
     * backlog → planning when the ready policy passes, or → ready when the card's plan is still current; PolicyRefused lists
     * the R-codes otherwise.
     */
    public function promote(string $id, Actor $by, ?Rev $expected = null): Card
    {
        $snapshot = $this->store->snapshot();
        $card = $snapshot->resolve($id);
        $refusals = $this->ready->refusals($card, $snapshot);
        if ($refusals !== []) {
            throw new PolicyRefused("refused {$card->id()}: ".implode('; ', $refusals), $refusals);
        }

        return $this->store->update($card->id(), fn (array $data) => self::stage($data,
            Plan::current(new Card($data, $card->board, $card->path, $card->rev)) ? 'ready' : 'planning', 'promote'), $by, $expected ?? $card->rev);
    }

    /**
     * ready → planning: the card's plan no longer covers it ($why), so a planner plans it again.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function replan(array $data, string $why): array
    {
        return self::stage($data, 'planning', 'replan', $why);
    }

    /**
     * A claim on the card for its next agent, with `work` when given, in one commit (pushed when sync=on). A ready card goes to
     * doing: preconditions (ready, a current plan, unblocked, deps satisfied, capacity unless forced or urgent+1). A planning
     * card stays in planning, taken by a planner: in planning and unclaimed, the ready policy, deps done, a slot `max_parallel`
     * leaves free unless forced or urgent+1.
     *
     * @param  array<string, mixed>|null  $work
     */
    public function start(string $id, Actor $by, ?Claim $claim = null, ?array $work = null, bool $force = false): Card
    {
        if ($force && ! $by->canForce()) {
            throw new PolicyRefused('--force is for the main session only');
        }
        $snapshot = $this->store->snapshot();
        if ($snapshot->resolve($id)->stage() === 'planning') {
            $card = $this->assertWaiting($snapshot, $id);

            return $this->store->claim($card->id(), $claim ?? Claim::here($by), $by, fn (Snapshot $fresh) => $this->assertPlannable($fresh, $card->id(), $force), $work,
                function (array $data) {
                    $data['log'][] = ['event' => 'claimed', 'for' => 'planning'];

                    return $data;
                });
        }
        $card = $this->assertReady($snapshot, $id);

        return $this->store->claim($card->id(), $claim ?? Claim::here($by), $by, fn (Snapshot $fresh) => $this->assertStartable($fresh, $card->id(), $force), $work);
    }

    /** The card when it waits in planning for a planner; PolicyRefused otherwise. */
    private function assertWaiting(Snapshot $snapshot, string $id): Card
    {
        $card = $snapshot->resolve($id);
        if ($card->stage() !== 'planning' || $card->claim() !== null) {
            throw new PolicyRefused("{$card->id()} is {$card->stage()}".($card->claim() ? ', being planned by '.$card->claim()['by'] : '').', not waiting for a planner');
        }

        return $card;
    }

    /** The card when a planner may take it against $snapshot (origin's, after the pull, when syncing); PolicyRefused otherwise. */
    private function assertPlannable(Snapshot $snapshot, string $id, bool $force): Card
    {
        $card = $this->assertWaiting($snapshot, $id);
        $refusals = $this->ready->refusals($card, $snapshot, false);
        if (! $snapshot->depsSatisfied($card)) {
            $refusals[] = 'dependencies not done: '.implode(', ', array_filter($card->dependsOn(), fn ($d) => ! $snapshot->isSatisfied($d)));
        }
        if ($refusals !== [] && ! $force) {
            throw new PolicyRefused("refused {$card->id()}: ".implode('; ', $refusals), $refusals);
        }
        $next = $this->pull->nextPlanning($snapshot, PHP_INT_MAX);
        if (! $force && ! in_array($card->id(), array_map(fn (Card $c) => $c->id(), $next['cards']), true)) {
            throw new PolicyRefused("refused {$card->id()}: ".($next['held'][$card->id()] ?? 'no capacity ('.$next['capacity']['used'].')'));
        }

        return $card;
    }

    /** The card when it is ready and unclaimed in $snapshot; PolicyRefused otherwise. */
    private function assertReady(Snapshot $snapshot, string $id): Card
    {
        $card = $snapshot->resolve($id);
        if ($card->stage() !== 'ready' || $card->claim() !== null) {
            throw new PolicyRefused("{$card->id()} is {$card->stage()}".($card->claim() ? ', claimed by '.$card->claim()['by'] : '').', not ready');
        }

        return $card;
    }

    /** The card when it may be claimed against $snapshot (origin's, after the pull, when syncing); PolicyRefused otherwise. */
    private function assertStartable(Snapshot $snapshot, string $id, bool $force): Card
    {
        $card = $this->assertReady($snapshot, $id);
        $refusals = $this->ready->refusals($card, $snapshot, false);
        if (! $snapshot->depsSatisfied($card)) {
            $refusals[] = 'dependencies not satisfied: '.implode(', ', array_filter($card->dependsOn(), fn ($d) => ! $snapshot->isSatisfied($d)));
        }
        if ($refusals !== [] && ! $force) {
            throw new PolicyRefused("refused {$card->id()}: ".implode('; ', $refusals), $refusals);
        }
        $next = $this->pull->next($snapshot, PHP_INT_MAX);
        if (! $force && ! in_array($card->id(), array_map(fn (Card $c) => $c->id(), $next['cards']), true)) {
            $reason = $this->pull->skipped($snapshot)[$card->id()] ?? $next['held'][$card->id()] ?? 'no capacity ('.$next['capacity']['reason'].')';
            throw new PolicyRefused("refused {$card->id()}: {$reason}");
        }

        return $card;
    }

    /**
     * doing → review from an applied worker report. $changes are merged into the card first (ticks, work.head, …).
     *
     * @param  array<string, mixed>  $changes
     */
    public function apply(string $id, Actor $by, array $changes = []): Card
    {
        return $this->store->update($id, fn (array $data) => self::stage(array_replace_recursive($data, $changes), 'review', 'apply'), $by);
    }

    /** review → doing: $via is reject (evaluator verdict), refresh (merge conflict) or move (owner send-back). */
    public function sendBack(string $id, string $via, Actor $by, ?string $note = null, ?Rev $expected = null, bool $force = false): Card
    {
        return $this->store->update($id, function (array $data) use ($via, $note, $force) {
            if (isset($data['work'])) {
                $data['work']['approved'] = null;
            }

            return self::stage($data, 'doing', $via, $note, $force);
        }, $by, $expected);
    }

    /** review → done after the merge; `work` is trimmed and records the merge commit. */
    public function finish(string $id, string $mergeSha, Actor $by): Card
    {
        return $this->store->update($id, function (array $data) use ($mergeSha) {
            $data['work']['merge'] = $mergeSha;
            $data['work']['finished'] = Clock::now();

            return self::stage($data, 'done', 'finish');
        }, $by);
    }

    /**
     * planning → ready once its planner's plan is on the card, current and made under its claim: the claim and `work` go, a
     * parked branch stays parked. StopCommand has taken the planning clone and stack down.
     */
    public function planned(string $id, Actor $by, ?string $parkedBranch = null): Card
    {
        $found = $this->store->card($id);

        return $this->store->update($found->id(), function (array $data) use ($parkedBranch, $found) {
            $card = new Card($data, $found->board, $found->path, $found->rev);
            if ($card->stage() !== 'planning' || ! Plan::madeUnderClaim($card)) {
                throw new PolicyRefused("{$card->id()} has no plan from its planner yet: it moves to ready once its planner's plan is applied");
            }
            if (! Plan::current($card)) {
                throw new PolicyRefused("{$card->id()}'s plan no longer covers it (its criteria or body changed since): its planner revises it");
            }
            $data = self::stage($data, 'ready', 'plan');
            if ($parkedBranch !== null) {
                $data['work'] = ['parked_branch' => $parkedBranch];
            }

            return $data;
        }, $by);
    }

    /**
     * planning → ready with a plan the owner or the main session wrote for a card no planner holds. $plan has passed
     * Plan::check against $base, the main commit it was made on.
     */
    public function plan(string $id, string $plan, string $base, Actor $by, ?Rev $expected = null): Card
    {
        return $this->store->update($id, function (array $data) use ($plan, $base) {
            if ($data['stage'] !== 'planning' || ($data['claim'] ?? null) !== null) {
                throw new PolicyRefused("{$data['id']} is {$data['stage']}".(($data['claim'] ?? null) !== null ? ', being planned' : '').': a plan is given to a card waiting in planning');
            }
            $data['plan'] = $plan;
            $data['log'][] = ['event' => 'planned', 'base' => $base, 'hash' => Plan::hash($data)];

            return self::stage($data, 'ready', 'plan');
        }, $by, $expected);
    }

    /** doing/review → ready (unblocked), backlog or dropped; a branch with commits is kept as work.parked_branch. planning → backlog or dropped. */
    public function stop(string $id, string $to, Actor $by, ?string $reason = null, ?string $parkedBranch = null, bool $force = false): Card
    {
        return $this->store->update($id, function (array $data) use ($to, $reason, $parkedBranch, $force) {
            $blocked = $data['blocked'] ?? null;
            $data = self::stage($data, $to, 'stop', $reason, $force);
            // back in ready it is to be started again, so what blocked the last attempt goes; an open question stays and keeps it out
            if (is_string($blocked) && (str_starts_with($blocked, 'start failed:') || ($to === 'ready' && ! str_starts_with($blocked, Card::QUESTION)))) {
                $data['blocked'] = null;
                $data['log'][array_key_last($data['log'])]['unblocked'] = $blocked;
            }
            if ($parkedBranch !== null) {
                $data['work'] = ['parked_branch' => $parkedBranch];
            }

            return $data;
        }, $by);
    }
}
