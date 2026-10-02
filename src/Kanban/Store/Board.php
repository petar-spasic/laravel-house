<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store;

final class Board
{
    /** @param  array<string, mixed>  $data */
    public function __construct(
        public readonly BoardRef $ref,
        public readonly array $data,
        public readonly Rev $rev,
    ) {}

    public function title(): string
    {
        return (string) ($this->data['title'] ?? $this->ref->board);
    }

    public function order(): int
    {
        return (int) ($this->data['order'] ?? 0);
    }

    public function wipDoing(): ?int
    {
        return $this->data['wip']['doing'] ?? null;
    }

    public function path(): string
    {
        return $this->ref.'/board.json';
    }
}
