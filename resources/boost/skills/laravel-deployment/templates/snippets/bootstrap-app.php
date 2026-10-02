<?php

// Merge into bootstrap/app.php's withMiddleware. TRUSTED_PROXIES: 127.0.0.1 locally (compose); in prod the reverse
// proxy's address as its requests arrive in the container (.env.prod).
# if:spa
// Prod's list also holds 127.0.0.1: SvelteKit's server-side calls, which carry the browser's address.
# endif
// Caddy's own trust (docker/Caddyfile) decides only its {client_ip}, never what Laravel sees.

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware): void {
        // env() here is deliberate: this closure runs before config is loaded,
        // and in Docker the value is a real process env var.
        $trustedProxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }
    })
    ->create();
