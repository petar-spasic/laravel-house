<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use Closure;
use PetarSpasic\LaravelHouse\Kanban\Protocol\MergeLease;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/**
 * The merge queue: approved review cards in approval order (`work.approved.at`, ties by id), one merge at a time for the
 * whole project. Approval order across machines trusts each machine's clock; nothing that must be right depends on it.
 */
final class MergeQueue
{
    /** @return list<Card> */
    public static function cards(Snapshot $snapshot): array
    {
        $queued = array_values($snapshot->cards(fn (Card $c) => $c->stage() === 'review' && $c->blocked() === null
            && is_string($c->work()['approved']['head'] ?? null) && is_string($c->work()['approved']['at'] ?? null)));
        usort($queued, fn (Card $a, Card $b) => [$a->work()['approved']['at'], $a->id()] <=> [$b->work()['approved']['at'], $b->id()]);

        return $queued;
    }

    /**
     * Why the queue waits, or null: a merge found main red (a `merge` entry `result: main`) on the base that is still
     * origin's main, which only a move of main lifts, so the next merge checks again; or a check of a queued card ran past
     * its timeout there (MergeTimeout).
     */
    public static function hold(Snapshot $snapshot, ?string $originMain): ?string
    {
        foreach (MainRed::open($snapshot, $originMain) as $row) {
            if ($row['holds']) {
                return "waits for main to be fixed (`{$row['command']}` fails on main at ".substr($row['base'], 0, 7).')';
            }
        }
        $timeouts = MergeTimeout::open($snapshot, $originMain);

        return $timeouts === [] ? null : MergeTimeout::hold($timeouts[0]);
    }

    /**
     * The queued cards that wait (hold()), with why, in queue order: all of them, or none.
     *
     * @return array<string, string>
     */
    public static function heldCards(Snapshot $snapshot, ?string $originMain): array
    {
        if (($hold = self::hold($snapshot, $originMain)) === null) {
            return [];
        }
        $held = [];
        foreach (self::cards($snapshot) as $card) {
            $held[$card->id()] = $hold;
        }

        return $held;
    }

    /**
     * Where each queued card stands, in queue order: `merging` (it holds the merge lease), `held` with what it waits for
     * (hold()), or `queued` with its place among the cards not held, from 1, the merging one first.
     *
     * @return array<string, array{state: string, position?: int, waits?: string}>
     */
    public static function states(Snapshot $snapshot, ?string $originMain): array
    {
        $hold = self::hold($snapshot, $originMain);
        $leased = $snapshot->mergeLease()['card'] ?? null;
        $cards = self::cards($snapshot);
        $place = $hold === null && array_filter($cards, fn (Card $c) => $c->id() === $leased) !== [] ? 1 : 0;
        $states = [];
        foreach ($cards as $card) {
            $states[$card->id()] = match (true) {
                $card->id() === $leased => ['state' => 'merging'],
                $hold !== null => ['state' => 'held', 'waits' => $hold],
                default => ['state' => 'queued', 'position' => ++$place],
            };
        }

        return $states;
    }

    /**
     * The card this machine merges next, and why no other: none while the queue holds (hold()), else the cards in order. A
     * card of this machine ($local) that may merge here now ($ready gives null) is it; one that may not is passed over
     * and named. A card of another machine stops the walk, unless $pass says to pass it over (MergeLease::observe).
     *
     * @param  Closure(Card): bool  $local
     * @param  Closure(Card): ?string  $ready  why the card cannot merge here now, null when it can
     * @param  Closure(Card): bool  $pass
     * @return array{card: ?Card, why: string}
     */
    public static function next(Snapshot $snapshot, ?string $originMain, Closure $local, Closure $ready, Closure $pass): array
    {
        $cards = self::cards($snapshot);
        if (($hold = self::hold($snapshot, $originMain)) !== null) {
            return ['card' => null, 'why' => implode('; ', array_map(fn (Card $c) => "{$c->id()} {$hold}", $cards)) ?: 'no approved card waits'];
        }
        $passed = [];
        foreach ($cards as $card) {
            if (! $local($card)) {
                if (! $pass($card)) {
                    return ['card' => null, 'why' => implode('; ', [...$passed, "{$card->id()} on ".($card->host() ?? 'another machine').' goes first'])];
                }
                $passed[] = "{$card->id()} passed over (at the front for ".intdiv(MergeLease::EXPIRE_SECONDS, 60).' min, not merged)';
            } elseif (($why = $ready($card)) !== null) {
                $passed[] = "{$card->id()} passed over ({$why})";
            } else {
                return ['card' => $card, 'why' => implode('; ', $passed)];
            }
        }

        return ['card' => null, 'why' => implode('; ', $passed) ?: 'no approved card waits'];
    }
}
