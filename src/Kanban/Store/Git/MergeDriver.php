<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Git;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Support\Ids;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;

/**
 * git merge driver for board JSON (`kanban merge-driver %O %A %B %P`). Pure: no lock, no app.
 * Coupled fields merge as groups; a true conflict takes the side with the newer `updated`, and what the other side
 * had is written into the card's log, so a merge never makes text or a stage move vanish.
 */
final class MergeDriver
{
    /** Card fields that only make sense together. */
    private const GROUPS = [
        ['stage', 'claim', 'work', 'blocked'],
    ];

    /** Text fields whose displaced value is kept in the log. */
    private const TEXTS = ['title', 'body', 'plan', 'blocked'];

    /** Where a card is in the flow; a displaced value is kept in the log as one line. */
    private const FLOW = ['stage', 'claim', 'work'];

    /** 0 = merged into $current, 1 = conflict (unparseable or a different card on the same path). */
    public static function run(string $ancestor, string $current, string $other, string $path): int
    {
        try {
            $o = is_file($ancestor) && trim((string) file_get_contents($ancestor)) !== '' ? Json::decode((string) file_get_contents($ancestor)) : [];
        } catch (Invalid) {
            $o = [];
        }
        try {
            $a = Json::decode((string) file_get_contents($current));
            $b = Json::decode((string) file_get_contents($other));
        } catch (Invalid) {
            return 1;
        }
        $kind = Json::kindOf($path);
        $merged = (new self)->merge($o, $a, $b, $kind);
        if ($merged === null) {
            return 1;
        }
        file_put_contents($current, Json::encode($merged, $kind));

        return 0;
    }

    /**
     * @param  array<string, mixed>  $o
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>|null null on an identity clash
     */
    public function merge(array $o, array $a, array $b, string $kind): ?array
    {
        if ($kind === 'card' && (($a['id'] ?? null) !== ($b['id'] ?? null) || ($a['created'] ?? null) !== ($b['created'] ?? null))) {
            return null;
        }
        $newer = $this->newer($a, $b, $kind);
        $keys = array_values(array_unique(array_merge(array_keys($a), array_keys($b))));
        $result = [];
        $grouped = [];
        if ($kind === 'card') {
            foreach (self::GROUPS as $group) {
                $present = array_values(array_filter($group, fn ($k) => in_array($k, $keys, true)));
                if ($present === []) {
                    continue;
                }
                $claims = in_array('claim', $present, true) && ($a['claim'] ?? null) !== null && ($b['claim'] ?? null) !== null && ! $this->same($a['claim'], $b['claim'])
                    && ! $this->same($o['claim'] ?? null, $a['claim']) && ! $this->same($o['claim'] ?? null, $b['claim']);
                // A claim one side made where the ancestor had none won its push: an edit that moved the card meanwhile
                // (a ready card back to planning, say) does not take it from its agent
                $claimed = fn (array $side) => in_array('claim', $present, true) && ($o['claim'] ?? null) === null && ($side['claim'] ?? null) !== null;
                // Two different claims: the one already on the upstream side (%A in a rebase) won its push, not the later one.
                $winner = match (true) {
                    $claims => $a,
                    $claimed($a) && ! $claimed($b) => $a,
                    $claimed($b) && ! $claimed($a) => $b,
                    default => $newer,
                };
                $picked = $this->threeWay($this->pick($o, $present), $this->pick($a, $present), $this->pick($b, $present), $this->pick($winner, $present));
                foreach ($present as $key) {
                    if (array_key_exists($key, $picked)) {
                        $result[$key] = $picked[$key];
                    }
                    $grouped[$key] = true;
                }
            }
        }
        foreach ($keys as $key) {
            if (isset($grouped[$key])) {
                continue;
            }
            if ($key === 'updated') {
                $result[$key] = max($a['updated'] ?? '', $b['updated'] ?? '');
            } elseif ($kind === 'card' && $key === 'log') {
                $result[$key] = $this->log($a['log'] ?? [], $b['log'] ?? []);
            } elseif ($kind === 'card' && $key === 'acceptance') {
                $result[$key] = $this->acceptance($o['acceptance'] ?? [], $a['acceptance'] ?? [], $b['acceptance'] ?? [], $newer === $a);
            } elseif ($kind === 'card' && in_array($key, Json::SETS, true)) {
                $result[$key] = $this->set($o[$key] ?? [], $a[$key] ?? [], $b[$key] ?? []);
            } else {
                $wrap = fn (array $side) => array_key_exists($key, $side) ? [$side[$key]] : [];
                $value = $this->threeWay($wrap($o), $wrap($a), $wrap($b), $wrap($newer));
                if ($value !== []) {
                    $result[$key] = $value[0];
                }
            }
        }

        if ($kind === 'card' && $o !== [] && isset($result['log'])) {
            $result['log'] = $this->withLosses($o, $a, $b, $result);
        }

        return $result;
    }

    /**
     * The merged log plus one `conflict` entry for every text, and every stage/claim/work state, that a side changed
     * since the ancestor and the merge did not keep. Content-addressed and stamped with the merged `updated`, so two
     * machines merging the same conflict write the same entry, and the union by id adds it once.
     *
     * @param  array<string, mixed>  $o
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  array<string, mixed>  $merged
     * @return list<array<string, mixed>>
     */
    private function withLosses(array $o, array $a, array $b, array $merged): array
    {
        $lost = [];
        foreach ([$a, $b] as $side) {
            foreach (self::TEXTS as $field) {
                $text = $side[$field] ?? null;
                if (is_string($text) && trim($text) !== '' && ! $this->same($text, $merged[$field] ?? null) && ! $this->same($text, $o[$field] ?? null)) {
                    $lost[] = [$field, $text];
                }
            }
            if (($o['blocked'] ?? null) !== null && ($side['blocked'] ?? null) === null && ($merged['blocked'] ?? null) !== null) {
                $lost[] = ['blocked', '(the block was cleared)'];
            }
            $flow = $this->pick($side, self::FLOW);
            if (! $this->same($flow, $this->pick($o, self::FLOW)) && ! $this->same($flow, $this->pick($merged, self::FLOW))) {
                $lost[] = ['flow', 'stage='.($flow['stage'] ?? '-').'; claim='.($flow['claim']['by'] ?? '-').'; work.head='.(isset($flow['work']['head']) ? substr((string) $flow['work']['head'], 0, 7) : '-')];
            }
        }
        $entries = $merged['log'];
        foreach ($lost as [$field, $text]) {
            $entries[] = ['id' => Ids::derived($field."\0".$text), 'at' => (string) ($merged['updated'] ?? ''), 'by' => 'hook', 'event' => 'conflict', 'field' => $field, 'lost' => $text];
        }

        return $this->log($entries, []);
    }

    /**
     * a == b ? a : a == o ? b : b == o ? a : newer.
     *
     * @template T
     *
     * @param  T  $o
     * @param  T  $a
     * @param  T  $b
     * @param  T  $newer
     * @return T
     */
    private function threeWay(mixed $o, mixed $a, mixed $b, mixed $newer): mixed
    {
        return match (true) {
            $this->same($a, $b) => $a,
            $this->same($a, $o) => $b,
            $this->same($b, $o) => $a,
            default => $newer,
        };
    }

    private function same(mixed $x, mixed $y): bool
    {
        return serialize($this->sorted($x)) === serialize($this->sorted($y));
    }

    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->sorted($item), $value);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function newer(array $a, array $b, string $kind): array
    {
        $order = strcmp((string) ($a['updated'] ?? ''), (string) ($b['updated'] ?? ''));
        if ($order === 0) {
            $order = strcmp(Json::encode($a, $kind), Json::encode($b, $kind));
        }

        return $order >= 0 ? $a : $b;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function pick(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }

    /**
     * @param  list<mixed>  $o
     * @param  list<mixed>  $a
     * @param  list<mixed>  $b
     * @return list<mixed>
     */
    private function set(array $o, array $a, array $b): array
    {
        $removed = array_merge(array_diff($o, $a), array_diff($o, $b));
        $merged = array_values(array_unique(array_diff(array_merge($o, $a, $b), $removed)));
        sort($merged, SORT_STRING);

        return $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $a
     * @param  list<array<string, mixed>>  $b
     * @return list<array<string, mixed>>
     */
    private function log(array $a, array $b): array
    {
        $entries = [];
        foreach (array_merge($a, $b) as $entry) {
            $entries[$entry['id'] ?? json_encode($entry)] ??= $entry;
        }
        $entries = array_values($entries);
        usort($entries, fn ($x, $y) => [$x['at'] ?? '', $x['id'] ?? ''] <=> [$y['at'] ?? '', $y['id'] ?? '']);

        return $entries;
    }

    /**
     * By id: text and done merge 3-way; added on one side is kept; deleted on one side and unchanged on the other is
     * dropped; the same new id with different text on both sides keeps A's and renumbers B's to max + 1.
     *
     * @param  list<array<string, mixed>>  $o
     * @param  list<array<string, mixed>>  $a
     * @param  list<array<string, mixed>>  $b
     * @return list<array<string, mixed>>
     */
    private function acceptance(array $o, array $a, array $b, bool $aIsNewer): array
    {
        [$o, $a, $b] = array_map(fn (array $items) => array_column($items, null, 'id'), [$o, $a, $b]);
        $ids = array_unique(array_merge(array_keys($o), array_keys($a), array_keys($b)));
        sort($ids);
        $result = [];
        $renumber = [];
        foreach ($ids as $id) {
            [$old, $mine, $theirs] = [$o[$id] ?? null, $a[$id] ?? null, $b[$id] ?? null];
            if ($mine !== null && $theirs !== null) {
                if ($old === null && $mine['text'] !== $theirs['text']) {
                    $result[] = $mine;
                    $renumber[] = $theirs;

                    continue;
                }
                $result[] = ['id' => $id] + array_combine(['text', 'done'], array_map(fn ($field) => $this->threeWay(
                    $old[$field] ?? null, $mine[$field], $theirs[$field], $aIsNewer ? $mine[$field] : $theirs[$field]), ['text', 'done']));

                continue;
            }
            $present = $mine ?? $theirs;
            if ($old === null || ! $this->same($present, $old)) {
                $result[] = $present;
            }
        }
        $max = max([0, ...array_keys($o), ...array_keys($a), ...array_keys($b)]);
        foreach ($renumber as $item) {
            $result[] = ['id' => ++$max] + $item;
        }

        return $result;
    }
}
