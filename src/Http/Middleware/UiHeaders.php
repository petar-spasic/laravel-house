<?php

namespace PetarSpasic\Kanban\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Board pages are never cached or indexed; only the versioned assets are cacheable. */
class UiHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response->headers->hasCacheControlDirective('immutable')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
