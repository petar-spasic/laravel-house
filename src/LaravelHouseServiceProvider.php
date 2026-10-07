<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use PetarSpasic\LaravelHouse\Rules\UpdateRulesCommand;
use PetarSpasic\LaravelHouse\Setup\HouseConfig;
use PetarSpasic\LaravelHouse\Validation\ExportValidationCommand;

final class LaravelHouseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExportValidationCommand::class, UpdateRulesCommand::class]);
        }
        // Composer's classmap loads it; a stale autoloader would otherwise make Boost drop every house guideline unseen
        class_exists(HouseConfig::class) || require_once dirname(__DIR__).'/resources/boost/skills/laravel-project-setup/scripts/HouseConfig.php';
        // The house guidelines (resources/boost/guidelines): `@houserules` holds in a project on the house rules,
        // `@houserules('htmx|spa')` when one of those modules is on too. config/house.php is read as install.php reads
        // it, never through a cached config, and a file it refuses fails the render, which boost:update reports.
        $this->callAfterResolving('blade.compiler', fn (BladeCompiler $blade) => $blade->if('houserules',
            fn (?string $gate = null) => is_file($this->app->basePath('config/house.php'))
                && HouseConfig::holds($gate, HouseConfig::read($this->app->basePath())->modules)));
    }
}
