<?php

namespace Tests\Unit\Application\UseCases;

use App\Application\UseCases\Oportunidad\OportunidadCsvImportUseCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Verifies the estado mapping in OportunidadCsvImportUseCase::resolveEstado()
 * is correct for ALL maestro IDs of "Estado oportunidad", the textual fallbacks,
 * and the "Generada" CSV variant.
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
    public function it_maps_maestro_id_20_enviado_to_Enviada(): void
    {
        // Maestro 20 → "Enviado" → internal "Enviada"
        $result = $this->resolve('20', [
            19 => 'Borrador',
            20 => 'Enviado',
            21 => 'En negociacion',
            22 => 'Ganado',
            23 => 'Perdido',
        ]);

        $this->assertSame('Enviada', $result);
    }

    #[Test]
    public function it_maps_maestro_id_22_ganado_to_Ganada(): void
    {
        $result = $this->resolve('22', [
            19 => 'Borrador',
            20 => 'Enviado',
            21 => 'En negociacion',
            22 => 'Ganado',
            23 => 'Perdido',
        ]);

        $this->assertSame('Ganada', $result);
    }

    #[Test]
    public function it_maps_maestro_id_23_perdido_to_Perdida(): void
    {
        $result = $this->resolve('23', [
            19 => 'Borrador',
            20 => 'Enviado',
            21 => 'En negociacion',
            22 => 'Ganado',
            23 => 'Perdido',
        ]);

        $this->assertSame('Perdida', $result);
    }

    #[Test]
    public function it_maps_maestro_id_19_borrador_to_Borrador(): void
    {
        $result = $this->resolve('19', [
            19 => 'Borrador',
            20 => 'Enviado',
            21 => 'En negociacion',
            22 => 'Ganado',
            23 => 'Perdido',
        ]);

        $this->assertSame('Borrador', $result);
    }

    #[Test]
    public function it_maps_maestro_id_21_en_negociacion_to_Aceptada(): void
    {
        $result = $this->resolve('21', [
            19 => 'Borrador',
            20 => 'Enviado',
            21 => 'En negociación',
            22 => 'Ganado',
            23 => 'Perdido',
        ]);

        $this->assertSame('Aceptada', $result);
    }

    #[Test]
    public function it_maps_text_generada_to_Enviada(): void
    {
        // 9 rows in oportunidades.csv use literal "Generada" instead of maestro ID
        $this->assertSame('Enviada', $this->resolve('Generada'));
    }

    #[Test]
    public function it_maps_text_enviado_to_Enviada(): void
    {
        $this->assertSame('Enviada', $this->resolve('Enviado'));
    }

    #[Test]
    public function it_maps_text_ganado_to_Ganada(): void
    {
        $this->assertSame('Ganada', $this->resolve('Ganado'));
    }

    #[Test]
    public function it_falls_back_to_Borrador_for_unknown_maestro_id(): void
    {
        // If the CSV has an ID that doesn't exist in maestros, fall back to Borrador
        $this->assertSame('Borrador', $this->resolve('99', [20 => 'Enviado']));
    }

    #[Test]
    public function it_falls_back_to_Borrador_for_null(): void
    {
        $this->assertSame('Borrador', $this->resolve(null));
    }

    #[Test]
    public function it_falls_back_to_Borrador_for_empty_string(): void
    {
        $this->assertSame('Borrador', $this->resolve(''));
    }

    #[Test]
    public function it_falls_back_to_Borrador_for_unknown_text(): void
    {
        $this->assertSame('Borrador', $this->resolve('xyz_unknown'));
    }
}
