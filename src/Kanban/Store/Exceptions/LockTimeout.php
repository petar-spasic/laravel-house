<?php

namespace PetarSpasic\Kanban\Store\Exceptions;

/** The board lock or a lease could not be taken in time. */
class LockTimeout extends KanbanException
{
    public const EXIT = 6;
}
