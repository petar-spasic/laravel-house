<?php

namespace PetarSpasic\LaravelHouse\Kanban\Support;

use Closure;

final class Ids
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const PATTERN = '/^[A-Z][A-Z0-9]{1,9}-[0-9A-HJKMNP-TV-Z]{4,12}$/';

    public static function random(int $length): string
    {
        $id = '';
        foreach (str_split(random_bytes($length)) as $byte) {
            $id .= self::ALPHABET[ord($byte) & 31];
        }

        return $id;
    }

    /**
     * A new card id. KANBAN_ID_SEQUENCE (comma-separated suffixes) is consumed first, for reproducible runs.
     *
     * @param  Closure(string): bool  $taken
     */
    public static function card(string $key, int $length, Closure $taken): string
    {
        $sequence = array_filter(array_map('trim', explode(',', (string) getenv('KANBAN_ID_SEQUENCE'))));
        foreach ($sequence as $suffix) {
            if (! $taken($id = $key.'-'.strtoupper($suffix))) {
                return $id;
            }
        }

        do {
            $id = $key.'-'.self::random($length);
        } while ($taken($id));

        return $id;
    }

    public static function log(): string
    {
        return self::random(8);
    }

    /** A log id that is a function of $seed: the same seed on any machine gives the same id. */
    public static function derived(string $seed): string
    {
        $bits = '';
        foreach (str_split(substr(sha1($seed, true), 0, 5)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn (string $chunk) => self::ALPHABET[bindec($chunk)], str_split($bits, 5)));
    }

    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }

    /** Uppercases and applies Crockford's aliases (I/L → 1, O → 0) to the part after the key. */
    public static function normalize(string $input): string
    {
        $input = strtoupper(trim($input));
        [$key, $suffix] = str_contains($input, '-') ? explode('-', $input, 2) : [null, $input];
        $suffix = strtr($suffix, ['I' => '1', 'L' => '1', 'O' => '0']);

        return $key === null ? $suffix : $key.'-'.$suffix;
    }

    public static function lower(string $id): string
    {
        return strtolower($id);
    }
}
