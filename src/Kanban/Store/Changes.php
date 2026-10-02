<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;

/** What one `Store::batch()` writes in a single commit. */
final class Changes
{
    /** @var array<string, array{card: Card, data: array<string, mixed>, to: BoardRef}> card id => the new data and board */
    public array $cards = [];

    /** @var list<string> board files to delete, relative to the board root */
    public array $removed = [];

    /** @var array<string, string> Markdown files at the board root => their bytes */
    public array $texts = [];

    /**
     * The card's new data (log entries without an id are completed as in `Store::update()`), on $to when it moves.
     *
     * @param  array<string, mixed>  $data
     */
    public function put(Card $card, array $data, ?BoardRef $to = null): self
    {
        $this->cards[$card->id()] = ['card' => $card, 'data' => $data, 'to' => $to ?? $card->board];

        return $this;
    }

    public function remove(string $path): self
    {
        $this->removed[] = $path;

        return $this;
    }

    public function text(string $path, string $bytes): self
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.md$/', $path) !== 1) {
            throw new Invalid("{$path}: only a Markdown file at the board root");
        }
        $this->texts[$path] = $bytes;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->cards === [] && $this->removed === [] && $this->texts === [];
    }
}
