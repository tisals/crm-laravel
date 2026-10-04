<?php

namespace Tests\Feature\Empresas\Application\Listeners;

use App\Empresas\Application\Listeners\EmpresaEnriquecimientoFailedLogger;
use App\Empresas\Domain\Events\EmpresaEnriquecimientoFailed;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR4 of `complementar-entidad` — work-unit 4.5 (RED + GREEN).
 *
 * `EmpresaEnriquecimientoFailedLogger` is the failure-path twin of
 * `EmpresaEnriquecidaLogger`. It writes a WARNING-level structured
 * log line so security/SRE dashboards can alert on enrichment
 * failures.
 */
class EmpresaEnriquecimientoFailedLoggerTest extends TestCase
{
    #[Test]
    public function handle_writes_warning_log_with_failure_payload(): void
    {
        $listener = new EmpresaEnriquecimientoFailedLogger();

        $event = new EmpresaEnriquecimientoFailed(
            entidad_id: 99,
            motivo: 'MCP server returned HTTP 503',
            attempts: 3,
        );

        Log::spy();

        $listener->handle($event);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($event) {
                return $message === 'empresa.enriquecimiento_failed'
                    && ($context['entidad_id'] ?? null) === 99
                    && ($context['motivo'] ?? null) === 'MCP server returned HTTP 503'
                    && ($context['attempts'] ?? null) === 3
                    && ($context['event_id'] ?? null) === $event->event_id;
            });
    }

    #[Test]
    public function listener_swallows_logging_errors_so_pipeline_does_not_break(): void
    {
        $listener = new EmpresaEnriquecimientoFailedLogger();

        $event = new EmpresaEnriquecimientoFailed(
            entidad_id: 1,
            motivo: 'something failed',
            attempts: 1,
        );

        // No Log facade spy — calling handle without Log should not throw.
        $listener->handle($event);
        $this->assertTrue(true);
    }
}