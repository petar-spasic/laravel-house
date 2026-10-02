<?php

namespace PetarSpasic\LaravelHouse\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use PetarSpasic\LaravelHouse\Kanban\KanbanServiceProvider;
use PetarSpasic\LaravelHouse\LaravelHouseServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaravelHouseServiceProvider::class, KanbanServiceProvider::class];
    }

    /** Sync is a decision each test makes for itself: a test with a remote is not synced behind its back. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('kanban.sync', 'off');
    }
}
