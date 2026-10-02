<?php

namespace PetarSpasic\LaravelHouse\Kanban\Code;

/** The database commands of a checkout: `migrate`, then `finish.after`, without a `--class=X` seeder the checkout lacks. */
final class DatabaseSteps
{
    /**
     * @param  array<string, mixed>  $config  the `kanban` config
     * @return list<string>
     */
    public static function commands(array $config, string $root): array
    {
        $package = require dirname(__DIR__, 3).'/config/kanban.php';
        $migrate = $config['migrate'] ?? null;
        $after = (array) (((array) ($config['finish'] ?? []) + $package['finish'])['after'] ?? []);

        return array_values(array_filter([...(is_string($migrate) && $migrate !== '' ? [$migrate] : []), ...array_map('strval', $after)],
            fn (string $command) => ! preg_match('/--class=(\S+)/', $command, $m)
                || is_file($root.'/database/seeders/'.class_basename(str_replace('\\\\', '\\', $m[1])).'.php')));
    }
}
