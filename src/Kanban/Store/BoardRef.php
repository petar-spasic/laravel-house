<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use Stringable;

/**
 * A board's slug: its directory under `docs/kanban`. A board of an older format also names the epic directory it sits
 * in (`epic/board`), until `fold-boards` moves it.
 */
final class BoardRef implements Stringable
{
    public const SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** Board slugs the local UI already uses as its own URL paths. */
    public const RESERVED = ['assets', 'boards', 'cards'];

    public function __construct(public readonly string $board, public readonly ?string $legacyEpic = null)
    {
        foreach (array_filter([$legacyEpic, $board], 'is_string') as $slug) {
            if (preg_match(self::SLUG, $slug) !== 1) {
                throw new Invalid("invalid slug '{$slug}' (expected lowercase words joined by '-')");
            }
        }
    }

    public static function parse(string $ref): self
    {
        $ref = trim($ref, '/');
        if (str_contains($ref, '/')) {
            throw new Invalid("invalid board '{$ref}' (expected a board slug, such as work; epics are a field of the card)");
        }

        return new self($ref);
    }

    public function equals(self $other): bool
    {
        return (string) $this === (string) $other;
    }

    public function __toString(): string
    {
        return $this->legacyEpic === null ? $this->board : $this->legacyEpic.'/'.$this->board;
    }
}
