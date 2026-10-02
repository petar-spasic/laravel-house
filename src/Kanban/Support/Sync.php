<?php

namespace PetarSpasic\LaravelHouse\Kanban\Support;

/**
 * The `sync` setting: `on` (also 1, true, yes) always pulls before and pushes after every write; `auto` does so while the
 * project has a remote; anything else is off, so a mistyped value never publishes a board.
 */
final class Sync
{
    public const PUBLISHED_BUT_OFF = 'sync off but this board is published: claims are not coordinated with other machines';

    /** @return 'on'|'auto'|'off' */
    public static function mode(mixed $value): string
    {
        $text = is_scalar($value) ? strtolower(trim(is_bool($value) ? ($value ? '1' : '0') : (string) $value)) : '';

        return match (true) {
            in_array($text, ['on', '1', 'true', 'yes'], true) => 'on',
            $text === 'auto' => 'auto',
            default => 'off',
        };
    }

    /** What `status` and the brief call the setting: `auto` is on while there is a remote, and reads as off without one. */
    public static function label(mixed $value, bool $remote = false): string
    {
        return match (self::mode($value)) {
            'on' => 'on',
            'auto' => $remote ? 'auto (on)' : 'off',
            default => 'off',
        };
    }
}
