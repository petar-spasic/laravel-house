<?php

namespace PetarSpasic\LaravelHouse\Kanban\Schema;

use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/**
 * JSON-Schema subset: type, enum, const, pattern, format (date, date-time: a real calendar date), minLength, maxLength, minimum, maximum, items, minItems,
 * maxItems, uniqueItems, properties, required, additionalProperties, anyOf, $ref (#/$defs/… in the same file).
 */
final class Validator
{
    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> "field: message"
     */
    public function validate(string $kind, array $data): array
    {
        $root = $this->schema($kind);
        $schema = $kind === 'card'
            ? $root['$defs'][($data['type'] ?? null) === 'decision' ? 'decision' : 'work']
            : $root;

        return $this->check($schema, $data, '', $root);
    }

    /**
     * Schema errors of every file in a snapshot.
     *
     * @return list<string> "relative/path.json: field: message"
     */
    public function snapshot(Snapshot $snapshot): array
    {
        $errors = [];
        $files = ['kanban.json' => ['kanban', $snapshot->kanban]];
        foreach ($snapshot->epics as $epic) {
            $files[$epic->path()] = ['epic', $epic->data];
        }
        foreach ($snapshot->boards as $board) {
            $files[$board->path()] = ['board', $board->data];
        }
        foreach ($snapshot->cards as $card) {
            $files[$card->path] = ['card', $card->data];
        }
        foreach ($files as $path => [$kind, $data]) {
            foreach ($this->validate($kind, $data) as $error) {
                $errors[] = "{$path}: {$error}";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @return list<string>
     */
    private function check(array $schema, mixed $value, string $path, array $root): array
    {
        if (isset($schema['$ref'])) {
            $schema = $this->resolve($schema['$ref'], $root) + array_diff_key($schema, ['$ref' => true]);
        }
        $at = $path === '' ? '' : $path.': ';

        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $option) {
                if ($this->check($option, $value, $path, $root) === []) {
                    return [];
                }
            }

            return $this->check(end($schema['anyOf']), $value, $path, $root);
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return [$at.'must be '.json_encode($schema['const'])];
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return [$at.'must be one of '.implode(', ', array_map(fn ($v) => json_encode($v), $schema['enum']))];
        }
        if (isset($schema['type']) && ! $this->typeMatches((array) $schema['type'], $value)) {
            return [$at.'must be '.implode(' or ', (array) $schema['type'])];
        }

        return match (true) {
            is_string($value) => $this->string($schema, $value, $at),
            is_int($value) => $this->number($schema, $value, $at),
            is_array($value) && array_is_list($value) && ! isset($schema['properties']) => $this->list($schema, $value, $path, $root),
            is_array($value) => $this->object($schema, $value, $path, $root),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function string(array $schema, string $value, string $at): array
    {
        $length = mb_strlen($value);
        if (isset($schema['minLength']) && $length < $schema['minLength']) {
            return [$at."must be at least {$schema['minLength']} characters"];
        }
        if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
            return [$at."must be at most {$schema['maxLength']} characters"];
        }
        if (isset($schema['pattern']) && preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/u', $value) !== 1) {
            return [$at."must match {$schema['pattern']}"];
        }
        if (isset($schema['format']) && ! self::formatMatches($schema['format'], $value)) {
            return [$at."must be a real {$schema['format']}"];
        }

        return [];
    }

    /** `date` and `date-time` values whose day exists (the pattern already fixed their shape). */
    private static function formatMatches(string $format, string $value): bool
    {
        if (! in_array($format, ['date', 'date-time'], true) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1) {
            return true;
        }
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }

        return $format === 'date' || preg_match('/T(\d{2}):(\d{2}):(\d{2})/', $value, $t) !== 1 || ((int) $t[1] < 24 && (int) $t[2] < 60 && (int) $t[3] < 60);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function number(array $schema, int $value, string $at): array
    {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            return [$at."must be at least {$schema['minimum']}"];
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            return [$at."must be at most {$schema['maximum']}"];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<mixed>  $value
     * @param  array<string, mixed>  $root
     * @return list<string>
     */
    private function list(array $schema, array $value, string $path, array $root): array
    {
        $at = $path === '' ? '' : $path.': ';
        if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
            return [$at."must have at most {$schema['maxItems']} items"];
        }
        if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
            return [$at."must have at least {$schema['minItems']} items"];
        }
        if (($schema['uniqueItems'] ?? false) && count(array_unique(array_map('serialize', $value))) !== count($value)) {
            return [$at.'must not repeat items'];
        }
        $errors = [];
        if (isset($schema['items'])) {
            foreach ($value as $i => $item) {
                array_push($errors, ...$this->check($schema['items'], $item, "{$path}[{$i}]", $root));
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $root
     * @return list<string>
     */
    private function object(array $schema, array $value, string $path, array $root): array
    {
        $prefix = $path === '' ? '' : $path.'.';
        $errors = [];
        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                $errors[] = "{$prefix}{$key}: is required";
            }
        }
        $properties = $schema['properties'] ?? [];
        foreach ($value as $key => $item) {
            if (isset($properties[$key])) {
                array_push($errors, ...$this->check($properties[$key], $item, $prefix.$key, $root));
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = "{$prefix}{$key}: unknown key";
            } elseif (is_array($schema['additionalProperties'] ?? null)) {
                array_push($errors, ...$this->check($schema['additionalProperties'], $item, $prefix.$key, $root));
            }
        }

        return $errors;
    }

    /** @param  list<string>  $types */
    private function typeMatches(array $types, mixed $value): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $root
     * @return array<string, mixed>
     */
    private function resolve(string $ref, array $root): array
    {
        $node = $root;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $node = $node[$segment];
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function schema(string $kind): array
    {
        return $this->schemas[$kind] ??= json_decode((string) file_get_contents(dirname(__DIR__, 3)."/schema/{$kind}.schema.json"), true, 64, JSON_THROW_ON_ERROR);
    }
}
