<?php

namespace PetarSpasic\LaravelHouse\Kanban\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * With `kanban.ui.token` set, a request carries it as ?token=, the X-Kanban-Token header or the cookie it sets. A page
 * without it shows a form that asks for it; a page given it in the query sets the cookie and drops it from the URL.
 */
class UiToken
{
    /** Named after the token: a cookie is shared by every port of a host, so each board on it needs a name of its own. */
    public static function cookie(string $token): string
    {
        return 'kanban_token_'.substr(hash('sha256', $token), 0, 8);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('kanban.asset')) {
            return $next($request);
        }
        $token = (string) config('kanban.ui.token');
        $given = (string) ($request->query('token') ?? $request->header('X-Kanban-Token') ?? $request->cookie(self::cookie($token)) ?? '');
        $page = ! $request->routeIs('kanban.api.*') && $request->isMethod('GET');
        if ($given === '' || ! hash_equals($token, $given)) {
            abort_unless($page, 401, 'Kanban UI token required');

            return response()->view('kanban::token', ['wrong' => $given !== '', 'keep' => $this->query($request)], 401);
        }

        $response = $page && $request->query('token') !== null ? $this->withoutToken($request) : $next($request);
        if ($request->query('token') !== null) {
            $response->headers->setCookie(new Cookie(self::cookie($token), $token, time() + 365 * 86400, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }

    /** The same page without the token, as a path, so the redirect does not depend on what host or proxy the request came through. */
    private function withoutToken(Request $request): RedirectResponse
    {
        $query = http_build_query($this->query($request), '', '&', PHP_QUERY_RFC3986);

        return new RedirectResponse($request->getBaseUrl().$request->getPathInfo().($query === '' ? '' : '?'.$query), 303);
    }

    /** @return array<string, string> */
    private function query(Request $request): array
    {
        return array_filter(array_diff_key($request->query(), ['token' => true]), is_string(...));
    }
}
