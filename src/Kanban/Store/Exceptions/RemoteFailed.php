<?php

namespace PetarSpasic\Kanban\Store\Exceptions;

/** The remote could not be reached or kept rejecting the push. */
class RemoteFailed extends KanbanException
{
    public const EXIT = 9;
}
