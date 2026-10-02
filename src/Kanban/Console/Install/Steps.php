<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console\Install;

use Illuminate\Contracts\Container\Container;
use PetarSpasic\LaravelHouse\Kanban\Console\InstallStep;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/** Registers the install steps under `InstallStep::TAG` (once per container) and builds them for `doctor`. */
final class Steps
{
    /** The steps `doctor` checks and `doctor --fix` re-runs, in order. */
    public const CHECKED = [ClaudeSettings::class, ClaudeAgents::class, Guidelines::class, GitIgnore::class, Compose::class];

    public static function register(Container $app): void
    {
        if ($app->bound(NextSteps::class)) {
            return;
        }
        $steps = [...self::CHECKED, NextSteps::class];
        foreach ($steps as $class) {
            $app->bind($class, fn (Container $app) => new $class($app->make(Paths::class), (array) $app->make('config')->get('kanban', [])));
        }
        $app->tag($steps, InstallStep::TAG);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<Step>
     */
    public static function make(Paths $paths, array $config): array
    {
        return array_map(fn (string $class) => new $class($paths, $config), self::CHECKED);
    }
}
