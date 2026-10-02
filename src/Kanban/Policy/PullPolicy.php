<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Priority;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/** Which ready cards to start next, and how many may start. */
final class PullPolicy
{
    /**
     * @return array{slots: int, reason: string|null, doing: int, max_parallel: int, review: int, review_limit: int}
     */
    public function capacity(Snapshot $snapshot, ?string $host = null): array
    {
        $host ??= (string) gethostname();
        $maxParallel = (int) $snapshot->setting('max_parallel', 6);
        $reviewLimit = (int) $snapshot->setting('wip.review', $maxParallel);
        $doing = $snapshot->cards(fn (Card $c) => $c->stage() === 'doing');
        $review = count($snapshot->cards(fn (Card $c) => $c->stage() === 'review'));
        $here = count(array_filter($doing, fn (Card $c) => in_array($c->host(), [null, $host], true)));

        $boardFree = 0;
        foreach ($snapshot->boards() as $board) {
            $onBoard = count(array_filter($doing, fn (Card $c) => $c->board->equals($board->ref)));
            $boardFree += $board->wipDoing() === null ? $maxParallel : max(0, $board->wipDoing() - $onBoard);
        }
        $slots = max(0, min($maxParallel - $here, $boardFree));
        $reason = match (true) {
            $slots > 0 => null,
            $maxParallel - $here <= 0 => "doing {$here}/{$maxParallel}",
            default => 'board WIP limits reached',
        };
        if ($review >= $reviewLimit) {
            [$slots, $reason] = [0, "review {$review}/{$reviewLimit} (stop starting)"];
        }

        return ['slots' => $slots, 'reason' => $reason, 'doing' => count($doing), 'here' => $here, 'max_parallel' => $maxParallel, 'review' => $review, 'review_limit' => $reviewLimit];
    }

    /** @return list<Card> startable ready cards in pull order */
    public function candidates(Snapshot $snapshot): array
    {
        $busyAreas = [];
        foreach ($snapshot->cards(fn (Card $c) => in_array($c->stage(), ['doing', 'review'], true)) as $card) {
            $busyAreas += array_flip($card->areas());
        }
        $cards = $snapshot->cards(fn (Card $c) => $c->stage() === 'ready'
            && $c->areas() !== []
            && $c->blocked() === null
            && $c->claim() === null
            && $snapshot->depsSatisfied($c)
            && array_intersect_key(array_flip($c->areas()), $busyAreas) === []);

        return $this->sort($snapshot, $cards, 'ready');
    }

    /**
     * Pull order: priority → epic order → board order → oldest in the stage → id.
     *
     * @param  list<Card>  $cards
     * @return list<Card>
     */
    public function sort(Snapshot $snapshot, array $cards, string $stage): array
    {
        $key = fn (Card $c) => [
            Priority::rank($c->priority()),
            $snapshot->epic($c->board->epic)?->order() ?? 0,
            $snapshot->boardOf($c)?->order() ?? 0,
            $c->stage() === $stage ? $c->stageSince() : $c->created(),
            $c->id(),
        ];
        $keys = [];
        foreach ($cards as $card) {
            $keys[$card->id()] = $key($card);
        }
        usort($cards, fn (Card $a, Card $b) => $keys[$a->id()] <=> $keys[$b->id()]);

        return $cards;
    }

    /**
     * Up to $count cards to start now. `urgent` may exceed capacity by one when no urgent card is in doing.
     *
     * @return array{cards: list<Card>, reason: string|null, capacity: array<string, mixed>}
     */
    public function next(Snapshot $snapshot, int $count = 1, ?string $host = null): array
    {
        $capacity = $this->capacity($snapshot, $host);
        $candidates = $this->candidates($snapshot);
        $urgentDoing = $snapshot->cards(fn (Card $c) => $c->stage() === 'doing' && $c->priority() === 'urgent') !== [];
        $boardFree = [];
        foreach ($snapshot->boards() as $board) {
            $onBoard = count($snapshot->cards(fn (Card $c) => $c->stage() === 'doing' && $c->board->equals($board->ref)));
            $boardFree[(string) $board->ref] = $board->wipDoing() === null ? PHP_INT_MAX : $board->wipDoing() - $onBoard;
        }

        $picked = [];
        $areas = [];
        $expedited = false;
        foreach ($candidates as $card) {
            if (count($picked) >= $count) {
                break;
            }
            if (array_intersect_key(array_flip($card->areas()), $areas) !== []) {
                continue;
            }
            $withinCapacity = count($picked) < $capacity['slots'] && ($boardFree[(string) $card->board] ?? 0) > 0;
            $expedite = ! $withinCapacity && ! $expedited && ! $urgentDoing && $card->priority() === 'urgent';
            if (! $withinCapacity && ! $expedite) {
                continue;
            }
            $expedited = $expedited || $expedite;
            $picked[] = $card;
            $areas += array_flip($card->areas());
            $boardFree[(string) $card->board] = ($boardFree[(string) $card->board] ?? 0) - 1;
        }

        $reason = null;
        if ($picked === []) {
            $reason = match (true) {
                $capacity['slots'] === 0 => $capacity['reason'],
                $candidates === [] => 'no startable ready cards',
                default => 'board WIP limits reached',
            };
        }

        return ['cards' => $picked, 'reason' => $reason, 'capacity' => $capacity];
    }
}
