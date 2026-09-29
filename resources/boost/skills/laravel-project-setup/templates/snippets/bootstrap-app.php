<?php

// The shape of bootstrap/app.php. Merge it in, keeping what install:api / install:broadcasting added; the
// TRUSTED_PROXIES block in withMiddleware is laravel-deployment's (references/project-files.md).

use App\Http\Middleware\AcceptJson;
use App\Http\Middleware\CachePublicResponse;
use App\Http\Middleware\HtmxOnly;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    // No listener auto-discovery: Event::listen in AppServiceProvider is the only registry
    // (app/Providers/CLAUDE.md) — discovery would double-fire every wired listener.
    ->withEvents(discover: false)
    // htmx. spa keeps `web:` and `api:`.
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Loaded via `then`, not `web`: nothing gets the `web` group implicitly.
        // Every route in web.php names its surface (routes/CLAUDE.md).
        then: fn () => require __DIR__.'/../routes/web.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Fortify's group middleware (config/fortify.php); must run before `auth` decides JSON vs redirect.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: AcceptJson::class);

        // htmx: the `public` group (routes/CLAUDE.md). Its limiter is defined in AppServiceProvider::boot(), which
        // also runs with cached routes. SubstituteBindings: a group outside `web` gets no route-model binding otherwise.
        $middleware->group('public', [CachePublicResponse::class, 'throttle:public', SubstituteBindings::class]);
        $middleware->alias(['htmx' => HtmxOnly::class]);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: HtmxOnly::class);

        // spa: $middleware->statefulApi(); — Sanctum authenticates the SPA by the session cookie + XSRF token.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
