<?php

namespace App\Application\Services;

use Illuminate\Support\Facades\DB;

/**
 * AuthAuditService — fire-and-forget writer for auth_audit_log.
 *
 * NOTE: If the `auth_audit_log` table doesn't exist (e.g. migrations not run),
 * this service silently logs to laravel.log instead of failing the request.
 */
class AuthAuditService
{
    public function log(string $event, ?int $usuarioId, string $ip, ?string $userAgent, ?string $requestId, array $metadata = []): void
    {
        // Siempre loguear a laravel.log (best-effort)
        try {
            \Illuminate\Support\Facades\Log::info("auth_audit: {$event}", [
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
