<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse;

use Illuminate\Support\ServiceProvider;
use PetarSpasic\LaravelHouse\Validation\ExportValidationCommand;

final class LaravelHouseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExportValidationCommand::class]);
        }
    }
}
