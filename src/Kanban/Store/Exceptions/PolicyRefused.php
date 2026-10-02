<?php

namespace PetarSpasic\Kanban\Store\Exceptions;

/** A transition, policy or precondition refuses the change. */
class PolicyRefused extends KanbanException
{
    public const EXIT = 3;
}
