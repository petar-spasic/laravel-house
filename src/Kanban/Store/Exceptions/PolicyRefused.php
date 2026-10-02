<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Exceptions;

/** A transition, policy or precondition refuses the change. */
class PolicyRefused extends KanbanException
{
    public const EXIT = 3;
}
