<?php

namespace PetarSpasic\LaravelHouse\Kanban\Support;

use JsonException;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use RuntimeException;

final class Json
{
    /** @var array<string, list<string>> */
    public const ORDER = [
        'kanban' => ['version', 'key', 'id_length', 'max_parallel', 'ready_buffer', 'wip', 'stale_after_minutes', 'locked', 'guard', 'updated'],
        'epic' => ['title', 'goal', 'done_when', 'body', 'order', 'updated'],
        'board' => ['title', 'body', 'order', 'wip', 'updated'],
        'card' => ['id', 'type', 'title', 'stage', 'priority', 'epic', 'labels', 'body', 'acceptance', 'depends_on', 'blocked',
            'claim', 'work', 'created', 'updated', 'log'],
        'guard' => ['strict', 'main_write_paths'],
        'acceptance' => ['id', 'text', 'done'],
        'claim' => ['by', 'session', 'at'],
        'work' => ['branch', 'base', 'worktree', 'host', 'stack', 'attempt', 'head', 'approved', 'merge', 'started', 'finished', 'parked_branch'],
        'stack' => ['project', 'slot', 'ports', 'url'],
        'log' => ['id', 'at', 'by', 'event'],
    ];

    public const SETS = ['labels', 'depends_on'];

    /** Keys whose value is a JSON object even when empty. */
    private const OBJECTS = ['wip', 'guard', 'ports', 'approved'];

    /** @return array<mixed> */
    public static function decode(string $bytes): array
    {
        try {
            $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new Invalid('invalid JSON: '.$e->getMessage());
        }
        if (! is_array($data)) {
            throw new Invalid('invalid JSON: not an object');
        }

        return $data;
    }

    /** @param  array<mixed>  $data */
    public static function encode(array $data, string $kind): string
    {
        return json_encode(self::objects(self::canonical($data, $kind)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /** Kind of a board file from its path relative to the board root. */
    public static function kindOf(string $relative): string
    {
        $base = basename($relative);

        return match (true) {
            $relative === 'kanban.json' => 'kanban',
            $base === 'epic.json', str_starts_with($relative, '_epics/') => 'epic',
            $base === 'board.json' => 'board',
            default => 'card',
        };
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function canonical(array $data, string $kind): array
    {
        $data = self::ordered($data, self::ORDER[$kind] ?? []);
        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            $data[$key] = match (true) {
                in_array($key, self::SETS, true) => self::set($value),
                $key === 'log' => self::log($value),
                $key === 'acceptance' => self::acceptance($value),
                $key === 'guard', $key === 'claim' => self::ordered($value, self::ORDER[$key]),
                $key === 'work' => self::work($value),
                default => $value,
            };
        }

        return $data;
    }

    /** Atomic write: temp file in the same directory, fsync, rename. */
    public static function write(string $path, string $bytes): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("cannot create {$dir}");
        }
        $tmp = $dir.'/.'.basename($path).'.'.bin2hex(random_bytes(4)).'.tmp';
        $handle = fopen($tmp, 'xb');
        if ($handle === false) {
            throw new RuntimeException("cannot write {$tmp}");
        }
        try {
            fwrite($handle, $bytes);
            fflush($handle);
            fsync($handle);
        } finally {
            fclose($handle);
        }
        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("cannot rename {$tmp}");
        }
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $order
     * @return array<mixed>
     */
    private static function ordered(array $data, array $order): array
    {
        if (array_is_list($data) && $data !== []) {
            return $data;
        }
        $known = array_intersect_key(array_flip($order), $data);
        $rest = array_diff_key($data, $known);
        ksort($rest, SORT_STRING);

        return array_replace($known, array_intersect_key($data, $known)) + $rest;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<mixed>
     */
    private static function set(array $values): array
    {
        $values = array_values(array_unique($values, SORT_REGULAR));
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param  array<mixed>  $entries
     * @return list<mixed>
     */
    private static function log(array $entries): array
    {
        $entries = array_map(fn ($entry) => is_array($entry) ? self::ordered($entry, self::ORDER['log']) : $entry, array_values($entries));
        usort($entries, fn ($a, $b) => [$a['at'] ?? '', $a['id'] ?? ''] <=> [$b['at'] ?? '', $b['id'] ?? '']);

        return $entries;
    }

    /**
     * @param  array<mixed>  $items
     * @return list<mixed>
     */
    private static function acceptance(array $items): array
    {
        $items = array_map(fn ($item) => is_array($item) ? self::ordered($item, self::ORDER['acceptance']) : $item, array_values($items));
        usort($items, fn ($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));

        return $items;
    }

    /**
     * @param  array<mixed>  $work
     * @return array<mixed>
     */
    private static function work(array $work): array
    {
        $work = self::ordered($work, self::ORDER['work']);
        if (is_array($work['stack'] ?? null)) {
            $work['stack'] = self::ordered($work['stack'], self::ORDER['stack']);
        }

        return $work;
    }

    private static function objects(mixed $value, ?string $key = null): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if ($value === []) {
            return in_array($key, self::OBJECTS, true) ? (object) [] : [];
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::objects($v, is_string($k) ? $k : null);
        }

        return $value;
    }
}
