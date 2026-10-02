<?php

namespace PetarSpasic\LaravelHouse\Kanban\Support;

use Dotenv\Dotenv as Loader;

final class DotEnv
{
    /** Loads `<dir>/.env` into the environment without overwriting what is already set. */
    public static function load(string $dir): void
    {
        Loader::createImmutable($dir)->safeLoad();
    }

    /** @return array<string, string|null> */
    public static function parse(string $file): array
    {
        return is_file($file) ? Loader::parse((string) file_get_contents($file)) : [];
    }
}
