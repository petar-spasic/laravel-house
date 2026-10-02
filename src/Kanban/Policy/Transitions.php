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
 * way to reach the moves that `move` refuses (start, apply, send back, finish, stop).
 */
final class Transitions
{
    /** "from>to" => vias that may perform it. `move` covers the CLI/UI; `promote` the ready gate. */
    private const ALLOWED = [
        'backlog>ready' => ['move', 'promote'],
        'ready>backlog' => ['move'],
        'ready>doing' => ['start'],
        'doing>review' => ['apply'],
        'review>doing' => ['reject', 'refresh', 'move'],
        'review>done' => ['finish'],
        'doing>ready' => ['stop'], 'doing>backlog' => ['stop'], 'doing>dropped' => ['stop'],
        'review>ready' => ['stop'], 'review>backlog' => ['stop'], 'review>dropped' => ['stop'],
        'backlog>dropped' => ['move'], 'ready>dropped' => ['move'],
        'dropped>backlog' => ['move'],
        // an open question of a version 1 board becomes a backlog spike
        'proposed>backlog' => ['fold-boards'],
    ];

    private const HINTS = [
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
        $data['stage'] = $to;
        if (Stage::isActive($from) && ! Stage::isActive($to)) {
            $data['claim'] = null;
            $data['work'] = $to === 'done'
                ? array_intersect_key($data['work'] ?? [], array_flip(['branch', 'base', 'merge', 'started', 'finished']))
                : null;
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
     * Owner/main move from the CLI or UI. Into ready runs the ready policy; review → doing needs a note.
     * $expected: the rev the caller read the card at (`Changed` when it moved on since); default the current one.
     */
    public function move(string $id, string $to, Actor $by, ?string $reason = null, bool $force = false, ?Rev $expected = null): Card
    {
        $card = $this->store->card($id);
        if (! $force && $to === 'ready' && $card->stage() === 'backlog') {
            return $this->promote($card->id(), $by, $expected);
        }
        if ($card->stage() === 'review' && $to === 'doing') {
            if (! $force && ($reason === null || trim($reason) === '')) {
                throw new PolicyRefused('sending back to doing needs a note (--reason)');
            }

            return $this->sendBack($card->id(), 'move', $by, $reason, $expected ?? $card->rev, $force);
        }

        return $this->store->update($card->id(), fn (array $data) => self::stage($data, $to, 'move', $reason, $force), $by, $expected ?? $card->rev);
    }

    /** backlog → ready when the ready policy passes; PolicyRefused lists the R-codes otherwise. */
    public function promote(string $id, Actor $by, ?Rev $expected = null): Card
    {
        $snapshot = $this->store->snapshot();
        $card = $snapshot->resolve($id);
        $refusals = $this->ready->refusals($card, $snapshot);
        if ($refusals !== []) {
            throw new PolicyRefused("refused {$card->id()}: ".implode('; ', $refusals), $refusals);
        }

        return $this->store->update($card->id(), fn (array $data) => self::stage($data, 'ready', 'promote'), $by, $expected ?? $card->rev);
    }

    /**
     * ready → doing: preconditions (ready, unblocked, deps satisfied, capacity unless forced or urgent+1), then the
     * claim (pushed first when sync=on), then `work` when given.
     *
     * @param  array<string, mixed>|null  $work
     */
    public function start(string $id, Actor $by, ?Claim $claim = null, ?array $work = null, bool $force = false): Card
    {
        if ($force && ! $by->canForce()) {
            throw new PolicyRefused('--force is for the main session only');
        }
        $card = $this->assertReady($this->store->snapshot(), $id);

        $claimed = $this->store->claim($card->id(), $claim ?? Claim::here($by), $by, fn (Snapshot $fresh) => $this->assertStartable($fresh, $card->id(), $force));

        return $work === null ? $claimed : $this->store->update($claimed->id(), function (array $data) use ($work) {
            $data['work'] = array_merge($data['work'] ?? [], $work);

            return $data;
        }, $by);
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
        if (! $force && ! in_array($card->id(), array_map(fn (Card $c) => $c->id(), $this->pull->next($snapshot, PHP_INT_MAX)['cards']), true)) {
            $capacity = $this->pull->capacity($snapshot);
            throw new PolicyRefused("refused {$card->id()}: no capacity (".($capacity['reason'] ?? 'area or WIP limit').')');
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

    /** doing/review → ready, backlog or dropped; a branch with commits is kept as work.parked_branch. */
    public function stop(string $id, string $to, Actor $by, ?string $reason = null, ?string $parkedBranch = null, bool $force = false): Card
    {
        return $this->store->update($id, function (array $data) use ($to, $reason, $parkedBranch, $force) {
            $data = self::stage($data, $to, 'stop', $reason, $force);
            if ($parkedBranch !== null) {
                $data['work'] = ['parked_branch' => $parkedBranch];
            }

            return $data;
        }, $by);
    }
}
