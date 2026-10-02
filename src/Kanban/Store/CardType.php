<?php

namespace PetarSpasic\LaravelHouse\Kanban\Store;

enum CardType: string
{
    case Feature = 'feature';
    case Bug = 'bug';
    case Chore = 'chore';
    case Spike = 'spike';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
