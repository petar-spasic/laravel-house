<?php

namespace PetarSpasic\Kanban\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** With `kanban.ui.token` set, a request carries it as ?token=, the X-Kanban-Token header or the cookie it sets. */
class UiToken
{
    public const COOKIE = 'kanban_token';

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('kanban.ui.token');
        $given = (string) ($request->query('token') ?? $request->header('X-Kanban-Token') ?? $request->cookie(self::COOKIE) ?? '');
        abort_unless($given !== '' && hash_equals($token, $given), 403, 'Kanban UI token required');

        $response = $next($request);
        if ($request->query('token') !== null) {
            $response->headers->setCookie(new Cookie(self::COOKIE, $token, 0, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }
}
