<?php

namespace PetarSpasic\Kanban\Store;

use Stringable;

/** Revision of a board file: sha1 of its bytes. */
final class Rev implements Stringable
{
    public function __construct(public readonly string $hash) {}

    public static function of(string $bytes): self
    {
        return new self(sha1($bytes));
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }

    public function __toString(): string
    {
        return $this->hash;
    }
}
