<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Exceptions;

use PetarSpasic\LaravelHouse\Kanban\Support\GitResult;

class GitFailed extends KanbanException
{
    public const EXIT = 1;

    public function __construct(string $message, public readonly ?GitResult $result = null)
    {
        parent::__construct($message);
    }
}
