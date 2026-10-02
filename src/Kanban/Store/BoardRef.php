<?php

namespace PetarSpasic\Kanban\Store;

use PetarSpasic\Kanban\Store\Exceptions\Invalid;
use Stringable;

final class BoardRef implements Stringable
{
    public const SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function __construct(public readonly string $epic, public readonly string $board)
    {
        foreach ([$epic, $board] as $slug) {
            if (preg_match(self::SLUG, $slug) !== 1) {
                throw new Invalid("invalid slug '{$slug}' (expected lowercase words joined by '-')");
            }
        }
    }

    /** Parses `epic/board`. */
    public static function parse(string $ref): self
    {
        $parts = explode('/', trim($ref, '/'));
        if (count($parts) !== 2) {
            throw new Invalid("invalid board '{$ref}' (expected epic/board)");
        }

        return new self($parts[0], $parts[1]);
    }

    public function equals(self $other): bool
    {
        return (string) $this === (string) $other;
    }

    public function __toString(): string
    {
        return $this->epic.'/'.$this->board;
    }
}
