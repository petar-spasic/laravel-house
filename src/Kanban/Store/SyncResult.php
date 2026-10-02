<?php

namespace PetarSpasic\Kanban\Store;

final class SyncResult
{
    /**
     * @param  string  $status  no-remote | pushed | up-to-date | pulled
     * @param  array<string, string>  $renamed  old id => new id
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $status,
        public readonly int $pulled = 0,
        public readonly int $pushed = 0,
        public readonly array $renamed = [],
        public readonly array $warnings = [],
    ) {}
}
