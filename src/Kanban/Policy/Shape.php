<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/** `hint:` lines on how cards are cut: a second open card on an area, a card many open cards wait on. Advice only. */
final class Shape
{
    /** Open dependents that make a card a hub. */
    public const HUB = 3;

    /**
     * Open cards with at least HUB open dependents, the most first.
     *
     * @return array<string, list<string>> card id => its open dependents
     */
    public static function hubs(Snapshot $snapshot): array
    {
        $dependents = [];
        foreach ($snapshot->cards(fn (Card $c) => self::open($c)) as $card) {
            foreach ($card->dependsOn() as $id) {
                $dependents[$id][] = $card->id();
            }
        }
        $hubs = array_filter($dependents, fn (array $ids, string $id) => count($ids) >= self::HUB && ($hub = $snapshot->card($id)) !== null && self::open($hub), ARRAY_FILTER_USE_BOTH);
        uksort($hubs, fn (string $a, string $b) => [count($hubs[$b]), $a] <=> [count($hubs[$a]), $b]);

        return $hubs;
    }

    /**
     * Hints after $card gained the $areas and $depends.
     *
     * @param  list<string>  $areas
     * @param  list<string>  $depends
     * @return list<string>
     */
    public static function hints(Snapshot $snapshot, Card $card, array $areas, array $depends): array
    {
        $hints = [];
        if (in_array($card->stage(), ['backlog', 'planning', 'ready'], true)) {
            foreach ($areas as $area) {
                $others = $snapshot->cards(fn (Card $c) => $c->id() !== $card->id() && in_array($c->stage(), ['backlog', 'planning', 'ready'], true) && in_array($area, $c->areas(), true));
                if ($others === []) {
                    continue;
                }
                usort($others, fn (Card $a, Card $b) => [$a->created(), $a->id()] <=> [$b->created(), $b->id()]);
                $other = $others[0];
                $more = count($others) > 1 ? ' (and '.(count($others) - 1).' more)' : '';
                $hints[] = "hint: {$area} already has an open card, {$other->id()} {$other->title()}{$more}; extend it, or fold this one into it: kanban fold {$card->id()} --into={$other->id()}";
            }
        }
        $hubs = self::hubs($snapshot);
        foreach ($depends as $id) {
            if (! isset($hubs[$id])) {
                continue;
            }
            $foldable = array_values(array_filter($hubs[$id], fn (string $c) => in_array($snapshot->card($c)?->stage(), ['backlog', 'planning', 'ready'], true)));
            $hints[] = "hint: {$id} blocks ".count($hubs[$id]).' open cards ('.implode(', ', $hubs[$id]).')'
                .($foldable === [] || ! in_array($snapshot->card($id)?->stage(), ['backlog', 'planning', 'ready'], true) ? ''
                    : '; if they are one piece of work: kanban fold '.implode(' ', $foldable)." --into={$id}");
        }

        return $hints;
    }

    private static function open(Card $card): bool
    {
        return ! in_array($card->stage(), ['done', 'dropped'], true);
    }
}
