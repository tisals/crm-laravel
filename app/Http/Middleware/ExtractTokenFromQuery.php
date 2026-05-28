<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Extracts Sanctum token from query string and sets it as Authorization header.
 * This allows browser <a> links to access ICS export endpoints.
 */
class ExtractTokenFromQuery
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->has('token') && !$request->bearerToken()) {
            $request->headers->set('Authorization', 'Bearer ' . $request->input('token'));
        }

        return $next($request);
    }
}
