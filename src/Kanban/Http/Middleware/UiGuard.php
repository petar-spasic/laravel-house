<?php

namespace PetarSpasic\Kanban\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What CSRF protection does for a UI without a session: a browser sends `Sec-Fetch-Site: cross-site` on requests other
 * sites cause, and cannot add a custom header (`X-Kanban`) to a cross-site write without a CORS preflight, which the
 * API never answers. The Host check keeps DNS rebinding out: a page that makes its own name resolve to this machine
 * is same-origin with it, but its Host is not one of ours.
 */
class UiGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->ownHost($this->host($request)), 403, 'Kanban answers only to this machine\'s own names; add the host to kanban.ui.hosts');
        $api = str_starts_with((string) $request->route()?->getName(), 'kanban.api.');
        if ($api) {
            $request->headers->set('Accept', 'application/json');
        }
        $navigation = ! $api && $request->isMethod('GET') && $request->header('Sec-Fetch-Mode') === 'navigate';
        abort_if($request->header('Sec-Fetch-Site') === 'cross-site' && ! $navigation, 403, 'Cross-site requests to the Kanban UI are refused');
        abort_if(! $request->isMethodSafe() && ! $this->sameOrigin($request), 403, 'Kanban writes must come from the Kanban page itself');
        abort_unless($request->isMethodSafe() || $request->headers->has('X-Kanban'), 403, 'Kanban writes need the X-Kanban header');

        return $next($request);
    }

    /** The Host the client sent: `getHost()` would trust X-Forwarded-Host from a trusted proxy, which a rebinding page can set. */
    private function host(Request $request): string
    {
        return strtolower(trim((string) parse_url('//'.$request->headers->get('Host'), PHP_URL_HOST), '[]'));
    }

    /** IP literals, localhost, the reserved .test names, and the hosts of the app URL, LOCAL_APP_URL and `ui.hosts`. */
    private function ownHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test')) {
            return true;
        }
        $own = [(string) parse_url((string) config('app.url'), PHP_URL_HOST), ...(array) config('kanban.ui.hosts', [])];

        return in_array($host, array_map('strtolower', array_filter($own)), true);
    }

    /**
     * A page on another local port is same-site, so Sec-Fetch-Site lets it through; its Origin gives it away. Scheme and
     * a port the request does not carry are not compared: behind a proxy the app cannot know them.
     */
    private function sameOrigin(Request $request): bool
    {
        $origin = $request->header('Origin');
        if ($origin === null) {
            return true;
        }
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $port = parse_url($origin, PHP_URL_PORT);
        $own = (string) $request->header('Host');
        $ownPort = parse_url('//'.$own, PHP_URL_PORT);

        return trim($host, '[]') === $this->host($request) && ($ownPort === null || $ownPort === $port || ($port === null && in_array($ownPort, [80, 443], true)));
    }
}
