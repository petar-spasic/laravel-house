<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Exceptions;

/** Another machine claimed the card first. */
class LostClaim extends KanbanException
{
    public const EXIT = 8;
}
