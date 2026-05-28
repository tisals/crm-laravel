<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiter que solo aplica a mutations (POST/PUT/DELETE).
 * GET/HEAD/OPTIONS pasan sin throttle.
 */
class ThrottleMutations
{
    public function __construct(
        private RateLimiter $limiter,
    ) {}

    public function handle(Request $request, Closure $next, string $limiterName = 'api'): Response
    {
        // Solo throttlear mutations
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return $next($request);
        }

        // Devolver al throttle normal de Laravel
        $throttle = app('Illuminate\Routing\Middleware\ThrottleRequests');
        return $throttle->handle($request, $next, $limiterName);
    }
}
