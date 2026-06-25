<?php

namespace Tests\Unit\Application\UseCases;

use App\Application\UseCases\Oportunidad\OportunidadCsvImportUseCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Verifies the estado mapping in OportunidadCsvImportUseCase::resolveEstado()
 * is correct after the pipeline refactor:
 *
 *   maestro.id 22 (Ganado)     → 'ACEPTADA'   (last positive stage, was legacy 'Ganada')
 *   maestro.id 23 (Perdido)    → 'RECHAZADA'  (last negative stage, was legacy 'Perdida')
 *   maestro.id 21 (En negociación) → 'EN_NEGOCIACION' (new stage)
 *   maestro.id 20 (Enviado)    → 'ENVIADA'
 *   maestro.id 19 (Borrador)   → 'BORRADOR'
 *   text 'Generada'            → 'ENVIADA'
 *
 * resolveEstado() is private — accessed via Reflection to keep production code
 * untouched and the test focused on the mapping contract.
 */
class OportunidadCsvImportUseCaseResolveEstadoTest extends TestCase
{
    private OportunidadCsvImportUseCase $useCase;

    private \ReflectionMethod $resolveEstado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useCase = new OportunidadCsvImportUseCase;

        $ref = new ReflectionClass($this->useCase);
        $this->resolveEstado = $ref->getMethod('resolveEstado');
        $this->resolveEstado->setAccessible(true);
    }

    private function resolve(?string $raw, array $estadoMap = []): string
    {
        $prop = (new ReflectionClass($this->useCase))->getProperty('estadoMap');
        $prop->setAccessible(true);
        $prop->setValue($this->useCase, $estadoMap);

        return (string) $this->resolveEstado->invoke($this->useCase, $raw);
    }

    #[Test]
    public function it_maps_maestro_id_20_enviado_to_ENVIADA(): void
    {
        $result = $this->resolve('20', [
            19 => 'Borrador',
            20 => 'Enviado',
            21 => 'En negociación',
            22 => 'Ganado',
            23 => 'Perdido',
        ]);

        $this->assertSame('ENVIADA', $result);
    }

    #[Test]
    public function it_maps_maestro_id_22_ganado_to_ACEPTADA(): void
    {
        $result = $this->resolve('22', [
            19 => 'Borrador',
            20 => 'Enviado',
            21 => 'En negociación',
            22 => 'Ganado',
            23 => 'Perdido',
        ]);

        $this->assertSame('ACEPTADA', $result);
    }

    #[Test]
    public function it_maps_maestro_id_23_perdido_to_RECHAZADA(): void
    {
        $result = $this->resolve('23', [
            19 => 'Borrador',
            20 => 'Enviado',
            21 => 'En negociación',
            22 => 'Ganado',
            23 => 'Perdido',
        ]);

        $this->assertSame('RECHAZADA', $result);
    }

    #[Test]
    public function it_maps_maestro_id_19_borrador_to_BORRADOR(): void
    {
        $result = $this->resolve('19', [
            19 => 'Borrador',
            20 => 'Enviado',
            21 => 'En negociación',
            22 => 'Ganado',
            23 => 'Perdido',
        ]);

        $this->assertSame('BORRADOR', $result);
    }

    #[Test]
    public function it_maps_maestro_id_21_en_negociacion_to_EN_NEGOCIACION(): void
    {
        $result = $this->resolve('21', [
            19 => 'Borrador',
            20 => 'Enviado',
            21 => 'En negociación',
            22 => 'Ganado',
            23 => 'Perdido',
        ]);

        $this->assertSame('EN_NEGOCIACION', $result);
    }

    #[Test]
    public function it_maps_text_generada_to_ENVIADA(): void
    {
        $this->assertSame('ENVIADA', $this->resolve('Generada'));
    }

    #[Test]
    public function it_maps_text_enviado_to_ENVIADA(): void
    {
        $this->assertSame('ENVIADA', $this->resolve('Enviado'));
    }

    #[Test]
    public function it_maps_text_ganado_to_ACEPTADA(): void
    {
        $this->assertSame('ACEPTADA', $this->resolve('Ganado'));
    }

    #[Test]
    public function it_falls_back_to_BORRADOR_for_unknown_maestro_id(): void
    {
        $this->assertSame('BORRADOR', $this->resolve('99', [20 => 'Enviado']));
    }

    #[Test]
    public function it_falls_back_to_BORRADOR_for_null(): void
    {
        $this->assertSame('BORRADOR', $this->resolve(null));
    }

    #[Test]
    public function it_falls_back_to_BORRADOR_for_empty_string(): void
    {
        $this->assertSame('BORRADOR', $this->resolve(''));
    }

    #[Test]
    public function it_falls_back_to_BORRADOR_for_unknown_text(): void
    {
        $this->assertSame('BORRADOR', $this->resolve('xyz_unknown'));
    }
}
