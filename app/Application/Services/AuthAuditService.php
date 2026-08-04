<?php

namespace App\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AuthAuditService — fire-and-forget writer for auth_audit_log.
 *
 * If the auth_audit_log table is missing or the schema is partial
 * (e.g. older deployments missing `updated_at`), this service silently
 * logs to laravel.log instead of failing the request.
 */
class AuthAuditService
{
    public function log(string $event, ?int $usuarioId, string $ip, ?string $userAgent, ?string $requestId, array $metadata = []): void
    {
        // Always log to laravel.log (best-effort)
        try {
            Log::info("auth_audit: {$event}", [
                'event' => $event,
                'usuario_id' => $usuarioId,
                'ip' => $ip,
                'request_id' => $requestId,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            // Ignore
        }

        // Best-effort DB write
        try {
            DB::table('auth_audit_log')->insert([
                'event' => $event,
                'email' => '',
                'usuario_id' => $usuarioId,
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? substr($userAgent, 0, 500) : null,
                'request_id' => $requestId,
                'metadata_json' => $metadata !== [] ? json_encode($metadata) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Silently fail
        }
    }
}
