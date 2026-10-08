<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prepended globally (bootstrap/app.php), so it runs before every other middleware: the id is in the Context, which
 * every log line, exception report and job dispatched during the request carries, and the response returns it as
 * X-Request-Id. A new id per request; a client's own header is never trusted.
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
