<?php

namespace PetarSpasic\Kanban\Store\Exceptions;

/** A git conflict, or the file changed since it was read (rev check). */
class Conflict extends KanbanException
{
    public const EXIT = 5;
}
