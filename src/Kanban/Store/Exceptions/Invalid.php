<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Exceptions;

/** Input or board data fails validation. */
class Invalid extends KanbanException
{
    public const EXIT = 2;
}
