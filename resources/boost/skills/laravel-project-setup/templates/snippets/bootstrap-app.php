<?php

// The shape of bootstrap/app.php. Merge it in, keeping what install:api / install:broadcasting added unless noted; the
// TRUSTED_PROXIES block in withMiddleware is laravel-deployment's (its bootstrap-app.php snippet).

use App\Http\Middleware\AcceptJson;
<!-- if:htmx -->
use App\Http\Middleware\CachePublicResponse;
use App\Http\Middleware\HtmxOnly;
<!-- endif -->
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
<!-- if:htmx -->
use Illuminate\Routing\Middleware\SubstituteBindings;
<!-- endif -->

return Application::configure(basePath: dirname(__DIR__))
    // No listener auto-discovery: Event::listen in AppServiceProvider is the only registry
    // (app/Providers/CLAUDE.md) — discovery would double-fire every wired listener.
    ->withEvents(discover: false)
    ->withRouting(
<!-- unless:htmx -->
        web: __DIR__.'/../routes/web.php',
<!-- endif -->
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
<!-- if:htmx -->
        // web.php is loaded via `then`, not `web`: nothing gets the `web` group implicitly.
        // Every route in web.php names its surface (routes/CLAUDE.md).
        then: fn () => require __DIR__.'/../routes/web.php',
<!-- endif -->
    )
<!-- if:spa -->
<!-- if:reverb -->
    // Channel auth under Caddy's /api/* matcher, at /api/broadcasting/auth. Drop the `channels:` argument
    // install:broadcasting adds to withRouting: it registers the default path, which reaches the SvelteKit app.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])
<!-- endif -->
<!-- endif -->
    ->withMiddleware(function (Middleware $middleware): void {
        // Fortify's group middleware (config/fortify.php); must run before `auth` decides JSON vs redirect.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: AcceptJson::class);
<!-- if:htmx -->

        // The `public` group (routes/CLAUDE.md). Its limiter is defined in AppServiceProvider::boot(), which also
        // runs with cached routes. SubstituteBindings: a group outside `web` gets no route-model binding otherwise.
        $middleware->group('public', [CachePublicResponse::class, 'throttle:public', SubstituteBindings::class]);
        $middleware->alias(['htmx' => HtmxOnly::class]);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: HtmxOnly::class);
<!-- endif -->
<!-- if:spa -->

        // Sanctum authenticates the SvelteKit app's api calls by the session cookie + XSRF token.
        $middleware->statefulApi();
<!-- endif -->
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
