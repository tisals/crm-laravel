<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Attaches X-Request-ID, X-Forwarded-For, user_agent to the request
 * for downstream middleware and use cases (audit, etc.).
 */
class AuditContextMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('audit_request_id', $request->header('X-Request-ID'));
        $request->attributes->set('audit_user_agent', $request->userAgent());

        if ($request->header('X-Forwarded-For')) {
            $request->attributes->set('audit_ip', explode(',', $request->header('X-Forwarded-For'))[0]);
        } else {
            $request->attributes->set('audit_ip', $request->ip());
        }

        return $next($request);
    }
}
