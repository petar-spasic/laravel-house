<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\KanbanException;

/** A worktree stack could not be allocated, started or checked (exit 7). */
class StackFailed extends KanbanException
{
    public const EXIT = 7;
}
