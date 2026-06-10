<?php

namespace Tests\Unit\Domain;

use App\Domain\Entities\PipelineEtapa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PipelineEtapaTest extends TestCase
{
    #[Test]
    public function it_creates_etapa_entity_from_array(): void
    {
        $data = [
            'id' => 1,
            'pipeline_id' => 1,
            'nombre' => 'Borrador',
            'orden' => 1,
            'habilitado' => true,
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
        ];

        $etapa = PipelineEtapa::fromArray($data);

        $this->assertInstanceOf(PipelineEtapa::class, $etapa);
        $this->assertEquals(1, $etapa->id);
        $this->assertEquals(1, $etapa->pipeline_id);
        $this->assertEquals('Borrador', $etapa->nombre);
        $this->assertEquals(1, $etapa->orden);
        $this->assertTrue($etapa->habilitado);
    }

    #[Test]
    public function it_creates_etapa_entity_with_defaults(): void
    {
        $data = [
            'id' => 1,
            'pipeline_id' => 1,
            'nombre' => 'Borrador',
            'orden' => 1,
        ];

        $etapa = PipelineEtapa::fromArray($data);

        $this->assertTrue($etapa->habilitado);
    }

    #[Test]
    public function it_converts_etapa_entity_to_array(): void
    {
        $data = [
            'id' => 1,
            'pipeline_id' => 1,
            'nombre' => 'Borrador',
            'orden' => 1,
            'habilitado' => true,
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
        ];

        $etapa = PipelineEtapa::fromArray($data);
        $result = $etapa->toArray();

        $this->assertEquals($data['id'], $result['id']);
        $this->assertEquals($data['pipeline_id'], $result['pipeline_id']);
        $this->assertEquals($data['nombre'], $result['nombre']);
        $this->assertEquals($data['orden'], $result['orden']);
        $this->assertEquals($data['habilitado'], $result['habilitado']);
    }
}
