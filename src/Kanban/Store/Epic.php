<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store;

/** A finite goal cards are assigned to (their `epic` field): `_epics/<slug>.json`, or `<slug>/epic.json` in an older board. */
final class Epic
{
    public const DIR = '_epics';

    /** @param  array<string, mixed>  $data */
    public function __construct(
        public readonly string $slug,
        public readonly array $data,
        public readonly Rev $rev,
        public readonly bool $legacy = false,
    ) {}

    public function title(): string
    {
        return (string) ($this->data['title'] ?? $this->slug);
    }

    public function goal(): string
    {
        return (string) ($this->data['goal'] ?? '');
    }

    /** @return list<string> */
    public function doneWhen(): array
    {
        return array_values(array_map('strval', (array) ($this->data['done_when'] ?? [])));
    }

    public function order(): int
    {
        return (int) ($this->data['order'] ?? 0);
    }

    public function path(): string
    {
        return self::pathOf($this->slug, $this->legacy);
    }

    public static function pathOf(string $slug, bool $legacy = false): string
    {
        return $legacy ? $slug.'/epic.json' : self::DIR.'/'.$slug.'.json';
    }
}
