<?php

namespace Tests\Unit\Empresas\Domain\Events;

use App\Empresas\Domain\Events\EmpresaEnriquecimientoFailed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PR4 of `complementar-entidad` — work-unit 4.6 (RED + GREEN).
 *
 * `EmpresaEnriquecimientoFailed` is emitted by the queue worker after
 * the job exhausts its retries (or fails fatally). The event MUST
 * carry enough context for downstream listeners to triage:
 *
 *   - `entidad_id`: int (subject)
 *   - `motivo`:     string (exception message; trimmed; empty rejected)
 *   - `attempts`:   int >= 1
 *   - `event_id`:   UUIDv4 (lets listeners dedup replays)
 *
 * The class is `final` and `readonly` so it serialises cleanly across
 * the queue boundary (mirrors `EmpresaEnriquecida`).
 */
class EmpresaEnriquecimientoFailedTest extends TestCase
{
    #[Test]
    public function constructor_assigns_all_properties(): void
    {
        $e = new EmpresaEnriquecimientoFailed(
            entidad_id: 42,
            motivo: 'MCP server returned HTTP 503',
            attempts: 3,
        );

        $this->assertSame(42, $e->entidad_id);
        $this->assertSame('MCP server returned HTTP 503', $e->motivo);
        $this->assertSame(3, $e->attempts);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $e->event_id
        );
    }

    #[Test]
    public function event_id_defaults_to_uuidv4_when_not_provided(): void
    {
        $a = new EmpresaEnriquecimientoFailed(1, 'A', 1);
        $b = new EmpresaEnriquecimientoFailed(2, 'B', 1);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $a->event_id
        );
        $this->assertNotSame($a->event_id, $b->event_id, 'two default event_ids must differ');
    }

    #[Test]
    public function explicit_event_id_is_preserved_when_supplied(): void
    {
        $e = new EmpresaEnriquecimientoFailed(
            entidad_id: 7,
            motivo: 'X',
            attempts: 1,
            eventId: '11111111-2222-4333-8444-555555555555',
        );

        $this->assertSame('11111111-2222-4333-8444-555555555555', $e->event_id);
    }

    #[Test]
    public function motivo_is_trimmed(): void
    {
        $e = new EmpresaEnriquecimientoFailed(1, '   trimmed reason   ', 1);
        $this->assertSame('trimmed reason', $e->motivo);
    }

    #[Test]
    public function empty_motivo_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/motivo/');

        new EmpresaEnriquecimientoFailed(1, '', 1);
    }

    #[Test]
    public function whitespace_only_motivo_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EmpresaEnriquecimientoFailed(1, '   ', 1);
    }

    #[Test]
    public function attempts_must_be_at_least_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/attempts/');

        new EmpresaEnriquecimientoFailed(1, 'X', 0);
    }

    #[Test]
    public function attempts_must_be_a_positive_integer(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EmpresaEnriquecimientoFailed(1, 'X', -3);
    }

    #[Test]
    public function invalid_explicit_event_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/event_id/');

        new EmpresaEnriquecimientoFailed(
            entidad_id: 1,
            motivo: 'X',
            attempts: 1,
            eventId: 'not-a-uuid',
        );
    }
}