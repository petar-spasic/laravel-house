<?php

namespace PetarSpasic\Kanban\Support;

final class GitResult
{
    public function __construct(
        public readonly int $code,
        public readonly string $out,
        public readonly string $err,
    ) {}

    public function ok(): bool
    {
        return $this->code === 0;
    }
}
