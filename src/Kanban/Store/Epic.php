<?php

namespace PetarSpasic\Kanban\Store;

final class Epic
{
    /** @param  array<string, mixed>  $data */
    public function __construct(
        public readonly string $slug,
        public readonly array $data,
        public readonly Rev $rev,
    ) {}

    public function title(): string
    {
        return (string) ($this->data['title'] ?? $this->slug);
    }

    public function order(): int
    {
        return (int) ($this->data['order'] ?? 0);
    }

    public function path(): string
    {
        return $this->slug.'/epic.json';
    }
}
