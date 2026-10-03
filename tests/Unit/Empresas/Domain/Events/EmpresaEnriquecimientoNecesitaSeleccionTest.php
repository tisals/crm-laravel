<?php

namespace Tests\Unit\Empresas\Domain\Events;

use App\Empresas\Domain\Events\EmpresaEnriquecimientoNecesitaSeleccion;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PR3 of `complementar-entidad` — work-unit 3.2 (RED).
 *
 * `EmpresaEnriquecimientoNecesitaSeleccion` is the "homonimia" event
 * emitted when `buscarPorDominio` returns 2+ candidates. The application
 * service persists NO annex row in this case; the event signals the
 * downstream layer to surface a selection UI to the user.
 *
 * Wire contract (design §11):
 *   - `event_id`: UUIDv4
 *   - `entidad_id`: int
 *   - `candidates_count`: int (>= 2)
 *   - `candidatos`: list<array> — full EmpresaCandidato payloads
 */
class EmpresaEnriquecimientoNecesitaSeleccionTest extends TestCase
{
    private const UUID_V4_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    #[Test]
    public function event_is_final_with_immutable_public_properties(): void
    {
        $reflection = new \ReflectionClass(EmpresaEnriquecimientoNecesitaSeleccion::class);
        $this->assertTrue($reflection->isFinal());

        foreach (['entidad_id', 'candidates_count', 'candidatos', 'event_id'] as $p) {
            $prop = $reflection->getProperty($p);
            $this->assertTrue($prop->isPublic());
            $this->assertTrue($prop->isReadOnly());
        }
    }

    #[Test]
    public function constructor_auto_stamps_uuidv4_event_id(): void
    {
        $e = new EmpresaEnriquecimientoNecesitaSeleccion(
            5,
            2,
            [
                ['razon_social' => 'A', 'nit' => '1', 'fuente_origen' => 'socrata'],
                ['razon_social' => 'B', 'nit' => '2', 'fuente_origen' => 'rues'],
            ],
        );

        $this->assertMatchesRegularExpression(self::UUID_V4_REGEX, $e->event_id);
    }

    #[Test]
    public function two_instantiations_have_distinct_event_ids(): void
    {
        $a = new EmpresaEnriquecimientoNecesitaSeleccion(1, 2, [['x' => 1], ['y' => 2]]);
        $b = new EmpresaEnriquecimientoNecesitaSeleccion(1, 2, [['x' => 1], ['y' => 2]]);

        $this->assertNotSame($a->event_id, $b->event_id);
    }

    #[Test]
    public function constructor_preserves_supplied_event_id(): void
    {
        $fixedId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $e = new EmpresaEnriquecimientoNecesitaSeleccion(
            9,
            2,
            [
                ['razon_social' => 'X', 'nit' => '1', 'fuente_origen' => 'socrata'],
                ['razon_social' => 'Y', 'nit' => '2', 'fuente_origen' => 'rues'],
            ],
            $fixedId,
        );
        $this->assertSame($fixedId, $e->event_id);
        $this->assertSame(9, $e->entidad_id);
        $this->assertSame(2, $e->candidates_count);
    }

    #[Test]
    public function constructor_rejects_candidates_count_below_two(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('candidates_count');

        new EmpresaEnriquecimientoNecesitaSeleccion(1, 1, []);
    }

    #[Test]
    public function constructor_rejects_empty_candidates_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('candidatos');

        new EmpresaEnriquecimientoNecesitaSeleccion(1, 2, []);
    }

    #[Test]
    public function constructor_rejects_mismatched_candidates_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EmpresaEnriquecimientoNecesitaSeleccion(
            1,
            5,
            [['razon_social' => 'X', 'nit' => '1', 'fuente_origen' => 'socrata']],
        );
    }

    #[Test]
    public function event_can_be_serialised_round_trip(): void
    {
        $candidatos = [
            ['razon_social' => 'A', 'nit' => '111', 'fuente_origen' => 'socrata'],
            ['razon_social' => 'B', 'nit' => '222', 'fuente_origen' => 'rues'],
        ];
        $e = new EmpresaEnriquecimientoNecesitaSeleccion(7, 2, $candidatos);

        $payload = json_decode(json_encode([
            'event_id' => $e->event_id,
            'entidad_id' => $e->entidad_id,
            'candidates_count' => $e->candidates_count,
            'candidatos' => $e->candidatos,
        ]), true);

        $this->assertSame(7, $payload['entidad_id']);
        $this->assertSame(2, $payload['candidates_count']);
        $this->assertCount(2, $payload['candidatos']);
    }
}