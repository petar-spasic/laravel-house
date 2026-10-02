<?php

namespace PetarSpasic\Kanban\Console;

use Illuminate\Config\Repository;
use Illuminate\Console\Application;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as RepositoryContract;
use Illuminate\Events\Dispatcher;
use PetarSpasic\Kanban\KanbanServiceProvider;
use PetarSpasic\Kanban\Support\DotEnv;
use PetarSpasic\Kanban\Support\Paths;
use Symfony\Component\Console\Input\ArgvInput;

/** `vendor/bin/kanban`: the kanban commands on Illuminate Console without booting the host app. */
final class Standalone
{
    /**
     * The main checkout's `vendor/bin/kanban` when $script is a code worktree's: a worktree's vendor/ is the copy taken
     * when its card started, while the board and its protocol are the main checkout's.
     */
    public static function mainCheckoutBinary(string $script): ?string
    {
        $script = realpath($script);
        if ($script === false || basename(dirname($script)) !== 'bin' || basename(dirname($script, 2)) !== 'vendor') {
            return null;
        }
        $root = dirname($script, 3);
        $main = Paths::discover($root)->main;

        return $main !== $root && is_file($main.'/vendor/bin/kanban') ? $main.'/vendor/bin/kanban' : null;
    }

    /** @param  list<string>  $argv */
    public static function run(array $argv): int
    {
        $paths = Paths::discover((string) getcwd());
        if ($paths->inRepo) {
            DotEnv::load($paths->main);
        }

        $container = new class extends Container
        {
            public function runningUnitTests(): bool
            {
                return false;
            }

            public function runningInConsole(): bool
            {
                return true;
            }
        };
        Container::setInstance($container);
        $container->instance('config', new Repository(['kanban' => self::config($paths->main)]));
        $container->alias('config', RepositoryContract::class);
        $container->instance(Paths::class, $paths);
        KanbanServiceProvider::bindStore($container);

        $application = new Application($container, new Dispatcher($container), 'laravel-kanban');
        $application->setName('kanban');
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);
        foreach (KanbanServiceProvider::commandClasses(standalone: true) as $class) {
            $application->resolve($class);
        }
        $application->setContainerCommandLoader();

        return $application->run(new ArgvInput(self::prefix($argv)));
    }

    /** `kanban list` → `kanban kanban:list`; `kanban help new` → `kanban help kanban:new`. */
    public static function prefix(array $argv): array
    {
        $out = [array_shift($argv) ?? 'kanban'];
        $named = false;
        foreach ($argv as $arg) {
            if (! $named && ! str_starts_with($arg, '-')) {
                if ($arg === 'help') {
                    $out[] = $arg;

                    continue;
                }
                $named = true;
                $arg = str_contains($arg, ':') ? $arg : 'kanban:'.$arg;
            }
            $out[] = $arg;
        }

        return $out;
    }

    /**
     * The package config merged with the project's config/kanban.php (top-level keys, like mergeConfigFrom).
     *
     * @return array<string, mixed>
     */
    public static function config(string $root): array
    {
        $config = require dirname(__DIR__, 2).'/config/kanban.php';
        $project = $root.'/config/kanban.php';

        return is_file($project) ? array_merge($config, (array) require $project) : $config;
    }
}
