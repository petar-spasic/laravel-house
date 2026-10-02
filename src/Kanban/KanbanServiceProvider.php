<?php

namespace PetarSpasic\LaravelHouse\Kanban;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\GitStore;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

class KanbanServiceProvider extends ServiceProvider
{
    /** Commands that need the booted app (artisan only); every other `src/Console/*Command.php` also runs standalone. */
    public const ARTISAN_COMMANDS = [
        Console\InstallCommand::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/kanban.php', 'kanban');
        $this->app->singleton(Paths::class, fn () => Paths::discover($this->app->basePath()));
        self::bindStore($this->app);
        Console\Install\Steps::register($this->app);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/kanban.php' => config_path('kanban.php')], 'kanban-config');
            $this->commands(self::commandClasses());
        }
        $this->bootUi();
    }

    /** @return list<class-string> */
    public static function commandClasses(bool $standalone = false): array
    {
        $classes = [];
        foreach (glob(__DIR__.'/Console/*Command.php') as $file) {
            $class = __NAMESPACE__.'\\Console\\'.basename($file, '.php');
            if ($class !== Console\Command::class && ! ($standalone && in_array($class, self::ARTISAN_COMMANDS, true))) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** Shared by the provider and the standalone CLI. Not a singleton: a store holds a transient lock flag. */
    public static function bindStore(Container $app): void
    {
        $app->bind(Store::class, fn (Container $app) => new GitStore($app->make(Paths::class), (array) $app->make('config')->get('kanban', [])));
    }

    /** Routes and views of the local UI are registered here. */
    protected function bootUi(): void
    {
        if (class_exists(Http\Ui::class)) {
            Http\Ui::boot($this->app);
        }
    }
}
