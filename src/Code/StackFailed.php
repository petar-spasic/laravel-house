<?php

namespace PetarSpasic\Kanban\Code;

use PetarSpasic\Kanban\Store\Exceptions\KanbanException;

/** A worktree stack could not be allocated, started or checked (exit 7). */
class StackFailed extends KanbanException
{
    public const EXIT = 7;
}
