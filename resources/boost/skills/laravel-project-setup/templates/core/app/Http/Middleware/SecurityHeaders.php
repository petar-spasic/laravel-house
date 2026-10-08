<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
# unless:spa
use Illuminate\Support\Facades\Vite;
# endif
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended globally (bootstrap/app.php; app/Http/Middleware/CLAUDE.md). Horizon is exempt: its dashboard is an
 * inline-script SPA behind its gate. So is a response rendered from an exception while APP_DEBUG is on: Laravel's debug
 * page is all inline script and style. A response that already carries a CSP keeps it (a package's own page).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $horizon = trim((string) config('horizon.path', 'horizon'), '/');
        if ($request->is($horizon, "{$horizon}/*")) {
            return $next($request);
        }
# unless:spa

# if:htmx
        // Read after $next: a page-cache hit puts back the nonce stored with the entry (app/Http/CLAUDE.md).
# endif
        Vite::useCspNonce();
# endif

        $response = $next($request);
        if (config('app.debug') && ($response->exception ?? null) !== null) {
            return $response;
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
# if:spa

        // Laravel serves JSON only; the pages' CSP is the SvelteKit app's (frontend/CLAUDE.md).
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
# endif
# unless:spa

        if (! $response->headers->has('Content-Security-Policy') && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Content-Security-Policy', $this->policy(Vite::cspNonce()));
        }
# endif

        return $response;
    }
# unless:spa

    /** Inline script only with the nonce, inline style allowed (style= attributes), and the Vite dev server while hot. */
    private function policy(string $nonce): string
    {
        $dev = Vite::isRunningHot() ? ' '.trim((string) file_get_contents(Vite::hotFile())) : '';
        $socket = $dev === '' ? '' : ' '.preg_replace('/^ http/', 'ws', $dev);

        return implode('; ', [
            "default-src 'self'",
# if:htmx
            "script-src 'self' 'nonce-{$nonce}' 'inline-speculation-rules'{$dev}",
# endif
# unless:htmx
            "script-src 'self' 'nonce-{$nonce}'{$dev}",
# endif
            "style-src 'self' 'unsafe-inline'{$dev}",
            "img-src 'self' data:{$dev}",
            "font-src 'self'{$dev}",
            "connect-src 'self'{$dev}{$socket}",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
        ]);
    }
# endif
}
