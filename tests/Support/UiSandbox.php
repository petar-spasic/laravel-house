<?php

namespace PetarSpasic\LaravelHouse\Tests\Support;

use PetarSpasic\LaravelHouse\Kanban\Http\Ui;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/** Points the testbench app at a sandbox checkout, as if the app ran from it, and boots the UI the way the provider does. */
final class UiSandbox
{
    /** @param  array<string, mixed>  $config */
    public static function boot(string $root, string $env = 'local', array $config = []): void
    {
        $app = app();
        $app->setBasePath($root);
        $app->instance(Paths::class, Paths::discover($root));
        $app['env'] = $env;
        config($config);
        Ui::boot($app);
    }
}
