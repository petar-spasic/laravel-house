<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Exceptions;

/** The card may not merge now: the merge lease is held elsewhere, or another card goes first. Never ends a `kanban run`. */
class Waiting extends KanbanException
{
    public const EXIT = 11;
}
