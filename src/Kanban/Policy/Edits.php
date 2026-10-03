<?php

namespace PetarSpasic\LaravelHouse\Kanban\Policy;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;

/** Changes to a card's acceptance criteria and log that the CLI (`set`) and the UI share, on card data. */
final class Edits
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $removed  ids removed earlier in the same change
     * @return array<string, mixed>
     */
    public static function add(array $data, string $text, array $removed = []): array
    {
        $items = self::criteria($data);
        $previous = [];
        foreach ($data['log'] ?? [] as $entry) {
            array_push($previous, ...($entry['acceptance_removed'] ?? []));
        }
        $items[] = ['id' => max([0, ...array_column($items, 'id'), ...$previous, ...$removed]) + 1, 'text' => $text, 'done' => false];
        $data['acceptance'] = $items;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function edit(array $data, int $id, string $text): array
    {
        $items = self::criteria($data);
        $items[self::position($items, $id)]['text'] = $text;
        $data['acceptance'] = $items;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $removed  collects the removed id
     * @return array<string, mixed>
     */
    public static function remove(array $data, int $id, array &$removed): array
    {
        $items = self::criteria($data);
        self::assertRemovable($data);
        self::position($items, $id);
        $data['acceptance'] = array_values(array_filter($items, fn (array $i) => $i['id'] !== $id));
        $removed[] = $id;

        return $data;
    }

    /**
     * A card in a locked stage takes a note, a blocked reason and ticked criteria, and with $reworded the new text of
     * existing criteria, and nothing else: refuses a change that touches any other field. $forced is the main session's
     * `--force`.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $locked  the locked stages, from kanban.json
     */
    public static function assertOpen(array $before, array $after, array $locked, bool $forced = false, bool $reworded = false): void
    {
        $stage = (string) ($before['stage'] ?? '');
        if ($forced || ! in_array($stage, $locked, true)) {
            return;
        }
        $frozen = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (in_array($key, ['blocked', 'epic', 'log', 'updated'], true)) {
                continue;
            }
            $was = $before[$key] ?? null;
            $is = $after[$key] ?? null;
            if ($key === 'acceptance') {
                $was = self::withoutTicks($was, $reworded);
                $is = self::withoutTicks($is, $reworded);
            }
            if ($was !== $is) {
                $frozen[] = $key;
            }
        }
        if ($frozen !== []) {
            sort($frozen);
            self::locked($before, $stage, implode(', ', $frozen).' cannot change; a note, a blocked reason, the epic, ticks and a criterion reworded with --reason can');
        }
    }

    /**
     * Refuses to send a card in a locked stage to another board.
     *
     * @param  array<string, mixed>  $card
     * @param  list<string>  $locked
     */
    public static function assertMovable(array $card, array $locked, bool $forced = false): void
    {
        if (! $forced && in_array((string) ($card['stage'] ?? ''), $locked, true)) {
            self::locked($card, (string) $card['stage'], 'it cannot move to another board');
        }
    }

    /** @param  array<string, mixed>  $card */
    private static function locked(array $card, string $stage, string $what): never
    {
        throw new PolicyRefused("{$card['id']} is in {$stage}, a locked stage: {$what} (--force from the main session overrides; `locked` in kanban.json lists the stages)");
    }

    /** @return mixed the criteria without their ticks, and without their text when $text */
    private static function withoutTicks(mixed $items, bool $text = false): mixed
    {
        $drop = $text ? ['done' => true, 'text' => true] : ['done' => true];

        return is_array($items) ? array_map(fn ($item) => is_array($item) ? array_diff_key($item, $drop) : $item, $items) : $items;
    }

    /**
     * Records each criterion whose text changed as a note (old → new: $reason) and unticks it, as the work no longer
     * shows it; a card in review goes back to doing, its approval with it.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    public static function reworded(array $before, array $after, string $reason, ?string $head = null): array
    {
        $was = array_column(self::criteria($before), 'text', 'id');
        $changed = array_values(array_filter(self::criteria($after), fn (array $c) => isset($was[$c['id']]) && $was[$c['id']] !== $c['text']));
        if ($changed === []) {
            throw new Invalid('--reason goes with a reworded criterion: accept[N]="…"');
        }
        foreach ($changed as $criterion) {
            $after = self::tick($after, $criterion['id'], false);
            $after = self::note($after, "criterion {$criterion['id']} reworded: {$was[$criterion['id']]} → {$criterion['text']}: {$reason}", $head);
        }
        if (($after['stage'] ?? null) !== 'review') {
            return $after;
        }
        if (is_array($after['work'] ?? null)) {
            $after['work']['approved'] = null;
        }

        return Transitions::stage($after, 'doing', 'move', 'criteria '.implode(', ', array_column($changed, 'id')).' reworded');
    }

    /** @param  array<string, mixed>  $data */
    public static function assertRemovable(array $data): void
    {
        if (! in_array($data['stage'], ['backlog', 'ready'], true)) {
            throw new PolicyRefused('acceptance criteria are never deleted once work started');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function tick(array $data, int $id, bool $done): array
    {
        $items = self::criteria($data);
        $items[self::position($items, $id)]['done'] = $done;
        $data['acceptance'] = $items;

        return $data;
    }

    /**
     * Makes the criteria equal to $incoming, in the UI's terms: an item with an `id` updates that criterion, one without
     * is added, and a criterion missing from the list is removed.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{id?: int|null, text: string, done?: bool}>  $incoming
     * @param  list<int>  $removed  collects the removed ids
     * @return array<string, mixed>
     */
    public static function replaceAcceptance(array $data, array $incoming, array &$removed): array
    {
        $existing = array_column(self::criteria($data), null, 'id');
        $kept = [];
        foreach ($incoming as $item) {
            if (($item['id'] ?? null) !== null) {
                if (! isset($existing[$item['id']])) {
                    throw new Invalid("no acceptance criterion {$item['id']}");
                }
                $kept[$item['id']] = true;
            }
        }
        foreach (array_keys(array_diff_key($existing, $kept)) as $id) {
            $data = self::remove($data, $id, $removed);
        }
        foreach ($incoming as $item) {
            if (($item['id'] ?? null) === null) {
                $data = self::add($data, $item['text'], $removed);
                if (! empty($item['done'])) {
                    $data = self::tick($data, max(array_column($data['acceptance'], 'id')), true);
                }

                continue;
            }
            $current = $existing[$item['id']];
            if (trim($item['text']) !== trim($current['text'])) {
                $data = self::edit($data, $item['id'], $item['text']);
            }
            if (isset($item['done']) && (bool) $item['done'] !== $current['done']) {
                $data = self::tick($data, $item['id'], (bool) $item['done']);
            }
        }

        return $data;
    }

    /**
     * Explains removed criteria in the log, so their ids are never reused.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<int>  $removed
     * @return array<string, mixed>
     */
    public static function recordRemoved(array $before, array $after, array $removed): array
    {
        if ($removed === []) {
            return $after;
        }
        $fields = array_keys(array_filter($after, fn ($v, $k) => $k !== 'log' && ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        sort($fields);
        $after['log'][] = ['event' => 'set', 'fields' => $fields, 'acceptance_removed' => $removed];

        return $after;
    }

    /**
     * $head: the commit of the card's worktree the note was made at.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function note(array $data, string $text, ?string $head = null): array
    {
        if (trim($text) === '') {
            throw new Invalid('note needs text');
        }
        $data['log'][] = array_filter(['event' => 'note', 'text' => $text, 'head' => $head], fn ($v) => $v !== null);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{id: int, text: string, done: bool}>
     */
    private static function criteria(array $data): array
    {
        return array_values($data['acceptance'] ?? []);
    }

    /** @param  list<array{id: int, text: string, done: bool}>  $items */
    private static function position(array $items, int $id): int
    {
        $position = array_search($id, array_column($items, 'id'), true);
        if ($position === false) {
            throw new NotFound("no acceptance criterion {$id}");
        }

        return $position;
    }
}
