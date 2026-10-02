<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Exceptions;

/** A staged worker report for a card that is no longer this machine's work in progress: it stays staged and changes nothing. */
class StaleReport extends PolicyRefused {}
