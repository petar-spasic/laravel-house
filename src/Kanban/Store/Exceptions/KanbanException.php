<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store\Exceptions;

use RuntimeException;

abstract class KanbanException extends RuntimeException
{
    public const EXIT = 1;

    /** @param  list<string>  $details  one fact per line, printed after the message */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function exitCode(): int
    {
        return static::EXIT;
    }
}
