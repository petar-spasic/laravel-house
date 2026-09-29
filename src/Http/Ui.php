<?php

namespace PetarSpasic\Kanban\Http;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use PetarSpasic\Kanban\Http\Middleware\UiHeaders;
use PetarSpasic\Kanban\Http\Middleware\UiToken;
use PetarSpasic\Kanban\Store\Stage;

/** The local board UI: only in the local environment, from the main checkout, with routes not cached. */
final class Ui
{
    public const ASSETS = ['kanban.css' => 'text/css; charset=utf-8', 'kanban.js' => 'text/javascript; charset=utf-8'];

    public static function boot(Application $app): void
    {
        if (! self::enabled($app)) {
            return;
        }
        $app['view']->addNamespace('kanban', dirname(__DIR__, 2).'/resources/views');

        /** @var Router $router */
        $router = $app['router'];
        $config = $app['config'];
        $router->middlewareGroup('kanban', array_values(array_filter([
            ...(array) $config->get('kanban.ui.middleware', []),
            UiHeaders::class,
            $config->get('kanban.ui.token') ? UiToken::class : null,
        ])));
        $router->prefix(trim((string) $config->get('kanban.ui.path', 'kanban'), '/'))
            ->middleware('kanban')
            ->name('kanban.')
            ->group(dirname(__DIR__, 2).'/routes/web.php');
        $app->booted(function () use ($router) {
            $router->getRoutes()->refreshNameLookups();
            $router->getRoutes()->refreshActionLookups();
        });
    }

    public static function enabled(Application $app): bool
    {
        return (bool) $app['config']->get('kanban.ui.enabled')
            && $app->environment('local')
            && is_dir($app->basePath('.git'))
            && ! $app->routesAreCached();
    }

    /** Stages the UI may move a card into; doing, review and done are the CLI's (start, apply, finish). */
    public const CLI_ONLY = ['doing', 'review', 'done'];

    /** @return list<string> */
    public static function targets(string $kind): array
    {
        return array_values(array_diff(Stage::forKind($kind), [...self::CLI_ONLY, 'superseded']));
    }

    /** Cache-busting version of a bundled asset. */
    public static function version(string $asset): string
    {
        return sha1_file(self::asset($asset)) ?: '';
    }

    public static function asset(string $asset): string
    {
        return dirname(__DIR__, 2).'/resources/dist/'.$asset;
    }
}
