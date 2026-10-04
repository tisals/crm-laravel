<?php

namespace Tests\Feature\Empresas\Application\Listeners;

use App\Empresas\Application\Listeners\EmpresaEnriquecidaLogger;
use App\Empresas\Domain\Events\EmpresaEnriquecida;
use App\Models\Entidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR4 of `complementar-entidad` — work-unit 4.5 (RED + GREEN).
 *
 * `EmpresaEnriquecidaLogger` is a thin observability listener that
 * writes a structured log line every time an entity is enriched.
 * Tests use Log::spy() to capture emitted log calls.
 */
class EmpresaEnriquecidaLoggerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function handle_writes_info_log_with_event_payload(): void
    {
        $listener = new EmpresaEnriquecidaLogger();

        $event = new EmpresaEnriquecida(
            entidad_id: 42,
            fuente_origen: 'socrata',
            enriquecido_at: '2026-10-03T12:00:00Z',
            enriquecimiento_hash: str_repeat('a', 64),
        );

        // Spy on the Log facade — capture every call.
        Log::spy();

        $listener->handle($event);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($event) {
                return $message === 'empresa.enriquecida'
                    && ($context['entidad_id'] ?? null) === 42
                    && ($context['fuente_origen'] ?? null) === 'socrata'
                    && ($context['enriquecido_at'] ?? null) === '2026-10-03T12:00:00Z'
                    && ($context['enriquecimiento_hash'] ?? null) === str_repeat('a', 64)
                    && ($context['event_id'] ?? null) === $event->event_id;
            });
    }

    #[Test]
    public function listener_does_not_throw_when_event_payload_is_minimal(): void
    {
        $listener = new EmpresaEnriquecidaLogger();

        $event = new EmpresaEnriquecida(
            entidad_id: 1,
            fuente_origen: 'manual',
            enriquecido_at: '2026-10-04T00:00:00Z',
            enriquecimiento_hash: str_repeat('b', 64),
        );

        // Just exercise it — no exception means the test passes.
        $listener->handle($event);
        $this->assertTrue(true);
    }
}