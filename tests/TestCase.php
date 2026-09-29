<?php

namespace PetarSpasic\Kanban\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use PetarSpasic\Kanban\KanbanServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [KanbanServiceProvider::class];
    }
}
