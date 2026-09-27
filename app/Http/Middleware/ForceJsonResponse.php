<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every /api/v1 route must always respond as JSON, regardless of what
 * Accept header the client sent — otherwise Laravel's auth middleware
 * falls back to a "redirect to login" behavior with no such route,
 * crashing into an HTML error page instead of a clean 401 JSON body.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
