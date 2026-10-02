<?php

namespace PetarSpasic\LaravelHouse\Kanban\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Board pages are never cached or indexed; only the versioned assets are cacheable. Card text is untrusted Markdown, so
 * the page may load nothing from other origins, cannot be framed, and leaks no Referer.
 */
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
        $response->headers->set('Content-Security-Policy', "default-src 'self'; base-uri 'none'; frame-ancestors 'none'");
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
