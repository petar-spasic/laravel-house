<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store;

enum Stage: string
{
    case Backlog = 'backlog';
    case Planning = 'planning';
    case Ready = 'ready';
    case Doing = 'doing';
    case Review = 'review';
    case Done = 'done';
    case Dropped = 'dropped';

    public const WORK = ['backlog', 'planning', 'ready', 'doing', 'review', 'done', 'dropped'];

    /** Stages whose cards always hold a claim; a planning card holds one while a planner works on it (Card::atWork). */
    public static function isActive(string $stage): bool
    {
        return $stage === 'doing' || $stage === 'review';
    }
}
