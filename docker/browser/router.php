<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application;
use PetarSpasic\Kanban\Http\Ui;
use PetarSpasic\Kanban\KanbanServiceProvider;
use PetarSpasic\Kanban\Support\Paths;

/* Router of the seeded UI server: a testbench app whose base path is the seeded checkout, the UI booted the way the provider does. */

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = getenv('KANBAN_UI_ROOT');
$app = Application::create(options: ['extra' => ['providers' => [KanbanServiceProvider::class]]]);
$app->make(Kernel::class)->bootstrap();
$app['env'] = 'local';
$app->setBasePath($root);
$app->instance(Paths::class, Paths::discover($root));
Ui::boot($app);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request = Request::capture());
$response->send();
$kernel->terminate($request, $response);
