<?php

namespace Tests\Unit\Empresas\Domain\Events;

use App\Empresas\Domain\Events\EmpresaEnriquecida;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PR3 of `complementar-entidad` — work-unit 3.2 (RED).
 *
 * `EmpresaEnriquecida` is the "happy path" event emitted after a
 * successful MCP lookup + Habeas filter + Decreto resolution + persist.
 *
 * Wire contract (design §11):
 *   - `event_id`: UUIDv4 (lets listeners dedup replays)
 *   - `entidad_id`: int
 *   - `fuente_origen`: string in {socrata|rues|manual}
 *   - `enriquecido_at`: ISO 8601 string
 *   - `enriquecimiento_hash`: 64-char lowercase hex
 *
 * Construction:
 *   - positional args required (no nullable wire fields)
 *   - auto-stamps `event_id` if not supplied
 *   - sealed — final class, not extensible
 */
class EmpresaEnriquecidaTest extends TestCase
{
    private const UUID_V4_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    #[Test]
    public function event_is_final_with_immutable_public_properties(): void
    {
        $reflection = new \ReflectionClass(EmpresaEnriquecida::class);
        $this->assertTrue($reflection->isFinal(), 'EmpresaEnriquecida event MUST be final (sealed).');

        foreach (['entidad_id', 'fuente_origen', 'enriquecido_at', 'enriquecimiento_hash', 'event_id'] as $p) {
            $prop = $reflection->getProperty($p);
            $this->assertTrue($prop->isPublic(), "Property {$p} MUST be public.");
            $this->assertTrue($prop->isReadOnly(), "Property {$p} MUST be readonly.");
        }
    }

    #[Test]
    public function constructor_auto_stamps_uuidv4_event_id(): void
    {
        $e = new EmpresaEnriquecida(
            123,
            'socrata',
            '2026-10-03T12:34:56Z',
            str_repeat('a', 64),
        );

        $this->assertMatchesRegularExpression(self::UUID_V4_REGEX, $e->event_id);
    }

    #[Test]
    public function two_instantiations_have_distinct_event_ids(): void
    {
        $a = new EmpresaEnriquecida(1, 'socrata', '2026-10-03T00:00:00Z', str_repeat('0', 64));
        $b = new EmpresaEnriquecida(1, 'socrata', '2026-10-03T00:00:00Z', str_repeat('0', 64));

        $this->assertNotSame($a->event_id, $b->event_id);
        $this->assertMatchesRegularExpression(self::UUID_V4_REGEX, $a->event_id);
        $this->assertMatchesRegularExpression(self::UUID_V4_REGEX, $b->event_id);
    }

    #[Test]
    public function constructor_preserves_supplied_event_id(): void
    {
        $fixedId = '11111111-2222-4333-8444-555555555555';
        $e = new EmpresaEnriquecida(
            7,
            'rues',
            '2026-10-03T00:00:00Z',
            str_repeat('f', 64),
            $fixedId,
        );

        $this->assertSame($fixedId, $e->event_id);
    }

    #[Test]
    public function constructor_rejects_invalid_event_id_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EmpresaEnriquecida(1, 'socrata', '2026-10-03T00:00:00Z', str_repeat('0', 64), 'not-a-uuid');
    }

    #[Test]
    public function constructor_rejects_unknown_fuente_origen(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EmpresaEnriquecida(1, 'bogus-source', '2026-10-03T00:00:00Z', str_repeat('0', 64));
    }

    #[Test]
    public function constructor_rejects_invalid_enriquecimiento_hash(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EmpresaEnriquecida(1, 'socrata', '2026-10-03T00:00:00Z', 'too-short');
    }

    #[Test]
    public function event_can_be_serialised_round_trip(): void
    {
        $e = new EmpresaEnriquecida(42, 'socrata', '2026-10-03T12:34:56Z', str_repeat('b', 64));

        $payload = json_decode(json_encode([
            'event_id' => $e->event_id,
            'entidad_id' => $e->entidad_id,
            'fuente_origen' => $e->fuente_origen,
            'enriquecido_at' => $e->enriquecido_at,
            'enriquecimiento_hash' => $e->enriquecimiento_hash,
        ]), true);

        $this->assertSame($e->event_id, $payload['event_id']);
        $this->assertSame(42, $payload['entidad_id']);
        $this->assertSame('socrata', $payload['fuente_origen']);
        $this->assertSame(str_repeat('b', 64), $payload['enriquecimiento_hash']);
    }
}