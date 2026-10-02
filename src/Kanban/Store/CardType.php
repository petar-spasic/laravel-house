<?php

namespace PetarSpasic\Kanban\Store;

enum CardType: string
{
    case Feature = 'feature';
    case Bug = 'bug';
    case Chore = 'chore';
    case Spike = 'spike';
    case Decision = 'decision';

    /** @return list<string> */
    public static function forKind(string $kind): array
    {
        return $kind === 'decisions' ? ['decision'] : ['feature', 'bug', 'chore', 'spike'];
    }

    public static function kindOf(string $type): string
    {
        return $type === 'decision' ? 'decisions' : 'work';
    }
}
