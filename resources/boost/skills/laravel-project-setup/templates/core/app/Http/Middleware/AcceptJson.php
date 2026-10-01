<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the routes it is attached to answer in JSON while Fortify's views are off: Fortify's own (config/fortify.php)
 * and those routes/CLAUDE.md names. Their non-JSON branches redirect to view routes (login, two-factor.login,
 * password.confirm) that do not exist.
 */
class AcceptJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
