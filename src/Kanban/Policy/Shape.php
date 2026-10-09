<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/**
 * `hint:` lines on how cards are cut: a second open card on an area, a card many open cards wait on, a body sentence or
 * a criterion too long to read at once. Advice only.
 */
final class Shape
{
    /** Open dependents that make a card a hub. */
    public const HUB = 3;

    /** Words outside backticks past which a body sentence or a criterion is long. */
    public const WORDS = 25;

    private const WHERE = '(kanban skill, references/planning.md, "The body and the criteria")';

    /** Body sections the board writes, not the person: skipped up to the next `##` heading. */
    private const GENERATED = '/^## (Folded from|Owner answer|Owner decision|Decision|Provisional decision|Open question)\b/';

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
     * Hints after $card gained the $areas and $depends, and took the $body and $criteria texts.
     *
     * @param  list<string>  $areas
     * @param  list<string>  $depends
     * @param  list<string>  $criteria
     * @return list<string>
     */
    public static function hints(Snapshot $snapshot, Card $card, array $areas, array $depends, ?string $body = null, array $criteria = []): array
    {
        $hints = [];
        $sentences = $body === null ? 0 : count(array_filter(self::sentences($body), fn (string $s) => self::words($s) > self::WORDS));
        if ($sentences > 0) {
            $hints[] = "hint: the body has {$sentences} ".($sentences === 1 ? 'sentence' : 'sentences').' over '.self::WORDS.' words: short sentences, one idea each '.self::WHERE;
        }
        $long = count(array_filter($criteria, fn (string $c) => self::words($c) > self::WORDS));
        if ($long > 0) {
            $hints[] = "hint: {$long} ".($long === 1 ? 'criterion has' : 'criteria have').' over '.self::WORDS.' words outside backticks: one outcome each '.self::WHERE;
        }
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

    /**
     * The body's prose sentences, without code, tables, headings and the generated sections. A sentence ends at . ! ?
     * before a space, at a blank line and at a list item.
     *
     * @return list<string>
     */
    private static function sentences(string $body): array
    {
        $sentences = [];
        $current = '';
        $fence = null;
        $skip = false;
        foreach (explode("\n", str_replace("\r\n", "\n", $body)) as $line) {
            if ($fence !== null) {
                $fence = str_starts_with(ltrim($line), $fence) ? null : $fence;

                continue;
            }
            if (preg_match('/^\s*(```|~~~)/', $line, $m) === 1) {
                $fence = $m[1];
                $line = '';
            } elseif (str_starts_with($line, '## ')) {
                $skip = preg_match(self::GENERATED, $line) === 1;
                $line = '';
            }
            if ($skip || trim($line) === '' || str_starts_with($line, '#') || preg_match('/^( {4}|\t)/', $line) === 1 || str_starts_with(ltrim($line), '|')) {
                $sentences[] = $current;
                $current = '';

                continue;
            }
            $line = trim(preg_replace('/`[^`]*`/', ' ', $line) ?? $line);
            if (preg_match('/^([-*+]|\d+\.)\s/', $line) === 1) {
                $sentences[] = $current;
                $current = '';
            }
            $parts = preg_split('/(?<=[.!?])\s+/', $current.' '.$line) ?: [];
            $current = (string) array_pop($parts);
            array_push($sentences, ...$parts);
        }
        $sentences[] = $current;

        return array_values(array_filter(array_map('trim', $sentences), fn (string $s) => $s !== ''));
    }

    /** Words outside backticked spans. */
    private static function words(string $text): int
    {
        $text = preg_replace('/`[^`]*`/', ' ', $text) ?? $text;

        return count(array_filter(preg_split('/\s+/', $text) ?: [], fn (string $w) => preg_match('/[\p{L}\p{N}]/u', $w) === 1));
    }

    private static function open(Card $card): bool
    {
        return ! in_array($card->stage(), ['done', 'dropped'], true);
    }
}
