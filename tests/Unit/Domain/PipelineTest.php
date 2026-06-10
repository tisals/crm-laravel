<?php

namespace Tests\Unit\Domain;

use App\Domain\Entities\Pipeline;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    #[Test]
    public function it_creates_pipeline_entity_from_array(): void
    {
        $data = [
            'id' => 1,
            'nombre' => 'Cotización',
            'codigo' => 'COTIZACION',
            'habilitado' => true,
            'etapas' => [],
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
        ];

        $pipeline = Pipeline::fromArray($data);

        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $this->assertEquals(1, $pipeline->id);
        $this->assertEquals('Cotización', $pipeline->nombre);
        $this->assertEquals('COTIZACION', $pipeline->codigo);
        $this->assertTrue($pipeline->habilitado);
        $this->assertIsArray($pipeline->etapas);
    }

    #[Test]
    public function it_creates_pipeline_entity_with_defaults(): void
    {
        $data = [
            'id' => 1,
            'nombre' => 'Cotización',
            'codigo' => 'COTIZACION',
        ];

        $pipeline = Pipeline::fromArray($data);

        $this->assertTrue($pipeline->habilitado);
        $this->assertIsArray($pipeline->etapas);
        $this->assertEmpty($pipeline->etapas);
    }

    #[Test]
    public function it_converts_entity_to_array(): void
    {
        $data = [
            'id' => 1,
            'nombre' => 'Cotización',
            'codigo' => 'COTIZACION',
            'habilitado' => true,
            'etapas' => [],
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
        ];

        $pipeline = Pipeline::fromArray($data);
        $result = $pipeline->toArray();

        $this->assertEquals($data['id'], $result['id']);
        $this->assertEquals($data['nombre'], $result['nombre']);
        $this->assertEquals($data['codigo'], $result['codigo']);
        $this->assertEquals($data['habilitado'], $result['habilitado']);
        $this->assertEquals($data['etapas'], $result['etapas']);
    }
}
