<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store;

enum Stage: string
{
    case Backlog = 'backlog';
    case Ready = 'ready';
    case Doing = 'doing';
    case Review = 'review';
    case Done = 'done';
    case Dropped = 'dropped';

    public const WORK = ['backlog', 'ready', 'doing', 'review', 'done', 'dropped'];

    /** Stages whose cards hold a claim. */
    public static function isActive(string $stage): bool
    {
        return $stage === 'doing' || $stage === 'review';
    }
}
