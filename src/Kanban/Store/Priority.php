<?php

namespace PetarSpasic\Kanban\Store;

enum Priority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    public static function rank(string $priority): int
    {
        return match ($priority) {
            'urgent' => 0,
            'high' => 1,
            'normal' => 2,
            default => 3,
        };
    }

    public static function short(string $priority): string
    {
        return match ($priority) {
            'urgent' => 'urg',
            'normal' => 'norm',
            default => $priority,
        };
    }
}
