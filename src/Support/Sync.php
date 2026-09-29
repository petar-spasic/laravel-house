<?php

namespace PetarSpasic\Kanban\Support;

/** The `sync` setting: `on` (also 1, true, yes) pulls before and pushes after every write; anything else is off. */
final class Sync
{
    public static function on(mixed $value): bool
    {
        return is_scalar($value) && in_array(strtolower(trim(is_bool($value) ? ($value ? '1' : '0') : (string) $value)), ['on', '1', 'true', 'yes'], true);
    }

    public static function label(mixed $value): string
    {
        return self::on($value) ? 'on' : 'off';
    }
}
