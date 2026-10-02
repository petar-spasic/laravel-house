<?php

namespace PetarSpasic\Kanban\Store\Exceptions;

/** A card, board or id prefix does not resolve to exactly one thing. */
class NotFound extends KanbanException
{
    public const EXIT = 4;
}
