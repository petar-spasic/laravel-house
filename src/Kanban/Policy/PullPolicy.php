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
        $full = [];
        foreach ($snapshot->boards() as $board) {
            $onBoard = count(array_filter($doing, fn (Card $c) => $c->board->equals($board->ref)));
            $boardFree += $board->wipDoing() === null ? $maxParallel : max(0, $board->wipDoing() - $onBoard);
            if ($board->wipDoing() !== null && $onBoard >= $board->wipDoing()) {
                $full[] = "board {$board->ref} doing {$onBoard}/{$board->wipDoing()}";
            }
        }
        $slots = max(0, min($maxParallel - $here, $boardFree));
        $reason = match (true) {
            $slots > 0 => null,
            $maxParallel - $here <= 0 => "doing {$here}/{$maxParallel}",
            default => implode(', ', $full),
        };
        if ($review >= $reviewLimit) {
            [$slots, $reason] = [0, "review {$review}/{$reviewLimit} (stop starting)"];
        }

        return ['slots' => $slots, 'reason' => $reason, 'doing' => count($doing), 'here' => $here, 'max_parallel' => $maxParallel, 'review' => $review, 'review_limit' => $reviewLimit];
    }

    /** @return list<Card> startable ready cards in pull order */
    public function candidates(Snapshot $snapshot): array
    {
        $skipped = $this->skipped($snapshot);

        return $this->sort($snapshot, $snapshot->cards(fn (Card $c) => $c->stage() === 'ready' && $c->claim() === null && ! isset($skipped[$c->id()])), 'ready');
    }

    /**
     * Why each unclaimed ready card is not startable, whatever the capacity, in pull order: `blocked: …`, `waits on ACME-Z
     * (doing)`, `area:billing busy (ACME-V review)`, `no area:* label`. For display only.
     *
     * @return array<string, string> id => reason
     */
    public function skipped(Snapshot $snapshot): array
    {
        $busy = [];
        foreach ($snapshot->cards(fn (Card $c) => in_array($c->stage(), ['doing', 'review'], true)) as $card) {
            foreach ($card->areas() as $area) {
                $busy[$area] ??= "{$card->id()} {$card->stage()}";
            }
        }
        $reasons = [];
        foreach ($this->sort($snapshot, $snapshot->cards(fn (Card $c) => $c->stage() === 'ready' && $c->claim() === null), 'ready') as $card) {
            $waits = array_values(array_filter($card->dependsOn(), fn (string $id) => ! $snapshot->isSatisfied($id)));
            $areas = array_values(array_filter($card->areas(), fn (string $area) => isset($busy[$area])));
            $reason = match (true) {
                $card->areas() === [] => 'no area:* label',
                $card->blocked() !== null => 'blocked: '.mb_strimwidth($card->blocked(), 0, 120, '…'),
                $waits !== [] => 'waits on '.implode(', ', array_map(fn (string $id) => $id.' ('.($snapshot->card($id)?->stage() ?? 'missing').')', $waits)),
                $areas !== [] => implode(', ', array_map(fn (string $area) => "{$area} busy ({$busy[$area]})", $areas)),
                default => null,
            };
            if ($reason !== null) {
                $reasons[$card->id()] = $reason;
            }
        }

        return $reasons;
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
            $snapshot->epicOf($c)?->order() ?? 0,
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
     * Up to $count cards to start now. `urgent` may exceed capacity by one when no urgent card is in doing. `held` is the
     * refusal for each startable card it passed over: the card ahead in its area, or the limit it hit.
     *
     * @return array{cards: list<Card>, reason: string|null, capacity: array<string, mixed>, held: array<string, string>}
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
        $held = [];
        $first = null;
        $expedited = false;
        foreach ($candidates as $card) {
            if (count($picked) >= $count) {
                break;
            }
            if (($taken = array_intersect_key($areas, array_flip($card->areas()))) !== []) {
                $held[$card->id()] = implode(', ', array_map(fn (string $area, string $id) => "{$area} goes to {$id} first", array_keys($taken), $taken))
                    ." (ahead in pull order): start that one, raise this card's priority, or --force";

                continue;
            }
            $free = $boardFree[(string) $card->board] ?? 0;
            $withinCapacity = count($picked) < $capacity['slots'] && $free > 0;
            $expedite = ! $withinCapacity && ! $expedited && ! $urgentDoing && $card->priority() === 'urgent';
            if (! $withinCapacity && ! $expedite) {
                $board = $snapshot->boardOf($card);
                $limit = match (true) {
                    $capacity['slots'] === 0 => (string) $capacity['reason'],
                    $free <= 0 && $board !== null => "board {$card->board} doing ".count($snapshot->cards(fn (Card $c) => $c->stage() === 'doing' && $c->board->equals($board->ref)))."/{$board->wipDoing()}",
                    default => "{$capacity['slots']} free slots go to ".implode(', ', array_map(fn (Card $c) => $c->id(), $picked)).' first',
                };
                $first ??= $limit;
                $held[$card->id()] = "no capacity ({$limit})";

                continue;
            }
            $expedited = $expedited || $expedite;
            $picked[] = $card;
            $areas += array_fill_keys($card->areas(), $card->id());
            $boardFree[(string) $card->board] = ($boardFree[(string) $card->board] ?? 0) - 1;
        }

        $reason = null;
        if ($picked === []) {
            $reason = match (true) {
                $capacity['slots'] === 0 => $capacity['reason'],
                $candidates === [] => 'no startable ready cards',
                default => (string) $first,
            };
        }

        return ['cards' => $picked, 'reason' => $reason, 'capacity' => $capacity, 'held' => $held];
    }
}
