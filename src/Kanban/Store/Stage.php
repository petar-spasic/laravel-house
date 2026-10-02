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
    case Proposed = 'proposed';
    case Decided = 'decided';
    case Superseded = 'superseded';

    public const WORK = ['backlog', 'ready', 'doing', 'review', 'done', 'dropped'];

    public const DECISIONS = ['proposed', 'decided', 'superseded', 'dropped'];

    /** @return list<string> */
    public static function forKind(string $kind): array
    {
        return $kind === 'decisions' ? self::DECISIONS : self::WORK;
    }

    public static function initial(string $kind): string
    {
        return $kind === 'decisions' ? 'proposed' : 'backlog';
    }

    /** Stages whose cards hold a claim. */
    public static function isActive(string $stage): bool
    {
        return $stage === 'doing' || $stage === 'review';
    }
}
