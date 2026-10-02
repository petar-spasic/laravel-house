<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Exceptions;

use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/** The board is in a format older than this package reads; only sync, the upgrade and the wiring commands run on it. */
class OldBoard extends PolicyRefused
{
    public function __construct()
    {
        parent::__construct(Snapshot::OLD_BOARD);
    }
}
