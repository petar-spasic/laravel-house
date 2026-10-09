<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Support\Json;

/** A file in a clone's working tree or `.git`, as this machine reads or writes it. */
final class CloneFile
{
    /** The directory $relative names in $clone, or null when there is none. */
    public static function within(string $clone, string $relative): ?string
    {
        $path = rtrim($clone, '/').'/'.ltrim($relative, '/');

        return is_dir($path) ? $path : null;
    }

    /** The content of $relative in $clone; null when it is no file. */
    public static function read(string $clone, string $relative): ?string
    {
        $path = rtrim($clone, '/').'/'.$relative;
        $content = is_file($path) ? @file_get_contents($path) : false;

        return $content === false ? null : $content;
    }

    /** A file an agent names on the command line: stdin for `-`, else a path, relative ones taken from $from. */
    public static function given(string $from, string $file): string
    {
        $content = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents(str_starts_with($file, '/') ? $file : rtrim($from, '/').'/'.$file);

        return is_string($content) ? $content : throw new NotFound("cannot read {$file}");
    }

    /** Writes $relative in $clone when its directory exists. Whether it did. */
    public static function write(string $clone, string $relative, string $content): bool
    {
        $path = rtrim($clone, '/').'/'.$relative;
        if (! is_dir(dirname($path))) {
            return false;
        }
        Json::write($path, $content);

        return true;
    }
}
