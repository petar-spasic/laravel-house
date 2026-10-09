<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use Closure;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Applier;
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
     * The command a card labelled main-red was filed for (its criterion "`<command>` passes on main"), or null: only such a
     * card is the fix for main red.
     */
    public static function fixes(Card $card): ?string
    {
        if (! in_array(Applier::MAIN_RED, $card->labels(), true)) {
            return null;
        }
        foreach ($card->acceptance() as $criterion) {
            if (preg_match('/^`(.+)` passes on main$/', (string) ($criterion['text'] ?? ''), $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Why the queue waits for main to turn green, or null: a merge found main red (a `merge` entry `result: main`) on the
     * base that is still origin's main, and the card it filed is open. Any move of main lifts the hold, so the next merge
     * checks again.
     */
    public static function hold(Snapshot $snapshot, ?string $originMain): ?string
    {
        if ($originMain === null) {
            return null;
        }
        foreach ($snapshot->cards() as $found) {
            foreach ($found->log() as $entry) {
                if (($entry['event'] ?? null) !== 'merge' || ($entry['result'] ?? null) !== 'main' || ($entry['base'] ?? null) !== $originMain) {
                    continue;
                }
                $red = $snapshot->card((string) ($entry['red'] ?? ''));
                if ($red !== null && ! in_array($red->stage(), ['done', 'dropped'], true)) {
                    return "waits for {$red->id()} (failing on main)";
                }
            }
        }

        return null;
    }

    /** Why $card waits for main to turn green (hold()), or null; a card that fixes main red (fixes()) never waits. */
    public static function held(Card $card, Snapshot $snapshot, ?string $originMain): ?string
    {
        return self::fixes($card) === null ? self::hold($snapshot, $originMain) : null;
    }

    /**
     * The queued cards that wait for main to turn green, with why (held()), in queue order.
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
            self::fixes($card) === null && $held[$card->id()] = $hold;
        }

        return $held;
    }

    /**
     * Where each queued card stands, in queue order: `merging` (it holds the merge lease), `held` with what it waits for
     * (held()), or `queued` with its place among the cards not held, from 1, the merging one first.
     *
     * @return array<string, array{state: string, position?: int, waits?: string}>
     */
    public static function states(Snapshot $snapshot, ?string $originMain): array
    {
        $hold = self::hold($snapshot, $originMain);
        $leased = $snapshot->mergeLease()['card'] ?? null;
        $cards = self::cards($snapshot);
        $held = fn (Card $card) => $hold !== null && self::fixes($card) === null;
        $place = array_filter($cards, fn (Card $c) => $c->id() === $leased && ! $held($c)) === [] ? 0 : 1;
        $states = [];
        foreach ($cards as $card) {
            $states[$card->id()] = match (true) {
                $card->id() === $leased => ['state' => 'merging'],
                $held($card) => ['state' => 'held', 'waits' => $hold],
                default => ['state' => 'queued', 'position' => ++$place],
            };
        }

        return $states;
    }

    /**
     * The card this machine merges next, and why no other: the cards not held, in order. A card of this machine ($local)
     * that may merge here now ($ready gives null) is it; one that may not is passed over and named. A card of another
     * machine stops the walk, unless $pass says to pass it over (MergeLease::observe).
     *
     * @param  Closure(Card): bool  $local
     * @param  Closure(Card): ?string  $ready  why the card cannot merge here now, null when it can
     * @param  Closure(Card): bool  $pass
     * @return array{card: ?Card, why: string}
     */
    public static function next(Snapshot $snapshot, ?string $originMain, Closure $local, Closure $ready, Closure $pass): array
    {
        $hold = self::hold($snapshot, $originMain);
        $passed = [];
        $holds = [];
        foreach (self::cards($snapshot) as $card) {
            if ($hold !== null && self::fixes($card) === null) {
                $holds[] = "{$card->id()} {$hold}";
            } elseif (! $local($card)) {
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

        return ['card' => null, 'why' => implode('; ', [...$passed, ...$holds]) ?: 'no approved card waits'];
    }
}
