<?php

namespace PetarSpasic\LaravelHouse\Kanban\Http;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use PetarSpasic\LaravelHouse\Kanban\Http\Middleware\UiGuard;
use PetarSpasic\LaravelHouse\Kanban\Http\Middleware\UiHeaders;
use PetarSpasic\LaravelHouse\Kanban\Http\Middleware\UiToken;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Store\Stage;

/** The local board UI: only in the local environment, from the main checkout, with routes not cached. */
final class Ui
{
    /** Fonts are named by revision and never change, so they are served immutable without a version query. */
    public const ASSETS = [
        'kanban.css' => 'text/css; charset=utf-8',
        'kanban.js' => 'text/javascript; charset=utf-8',
        'kanban.svg' => 'image/svg+xml',
        'inter-2-400.woff2' => 'font/woff2',
        'inter-2-500.woff2' => 'font/woff2',
        'inter-2-600.woff2' => 'font/woff2',
    ];

    public static function boot(Application $app): void
    {
        if (! self::enabled($app)) {
            return;
        }
        $app['view']->addNamespace('kanban', dirname(__DIR__, 3).'/resources/views');
        $prefix = trim((string) $app['config']->get('kanban.ui.path', 'kanban'), '/');
        // Markdown keeps its leading whitespace
        $api = fn (Request $request) => $request->is(ltrim($prefix.'/_api/*', '/'));
        TrimStrings::skipWhen($api);

        /** @var Router $router */
        $router = $app['router'];
        $config = $app['config'];
        // a published config may lack the `hosts` key, and the config merge is shallow
        $config->set('kanban.ui.hosts', array_values(array_unique(array_filter([...(array) $config->get('kanban.ui.hosts', []), parse_url((string) env('LOCAL_APP_URL'), PHP_URL_HOST) ?: null]))));
        $router->middlewareGroup('kanban', array_values(array_filter([
            // the UI keeps no session and sends no CSRF token, so a published config that still lists `web` is ignored on that entry
            ...array_diff((array) $config->get('kanban.ui.middleware', []), ['web']),
            UiHeaders::class,
            UiGuard::class,
            $config->get('kanban.ui.token') ? UiToken::class : null,
        ])));
        $router->prefix($prefix)
            ->middleware('kanban')
            ->name('kanban.')
            ->group(dirname(__DIR__, 3).'/routes/web.php');
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

    /**
     * Where the UI may move a card that is in each stage of a board kind (never into doing, review or done).
     *
     * @return array<string, list<string>>
     */
    public static function moves(string $kind): array
    {
        $moves = [];
        foreach (Stage::forKind($kind) as $stage) {
            $moves[$stage] = array_values(array_diff(Transitions::moveTargets($kind, $stage), self::CLI_ONLY));
        }

        return $moves;
    }

    /** Cache-busting version of a bundled asset. */
    public static function version(string $asset): string
    {
        return sha1_file(self::asset($asset)) ?: '';
    }

    public static function asset(string $asset): string
    {
        return dirname(__DIR__, 3).'/resources/dist/'.$asset;
    }
}
