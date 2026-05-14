<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\BaseResource;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BaseResourceTest extends TestCase
{
    #[Test]
    public function it_wraps_entity_in_envelope_structure(): void
    {
        $entity = \App\Domain\Entities\Rol::fromArray([
            'id' => 1,
            'nombre' => 'Admin',
            'estado' => 'Activo',
            'created_by' => null,
            'updated_by' => null,
            'created_at' => '2026-01-01T00:00:00.000000Z',
            'updated_at' => '2026-01-01T00:00:00.000000Z',
        ]);

        $resource = new BaseResource($entity);
        $response = $resource->toArray(request());

        $this->assertArrayHasKey('id', $response);
        $this->assertEquals(1, $response['id']);
        $this->assertEquals('Admin', $response['nombre']);
    }

    #[Test]
    public function it_returns_collection_structure(): void
    {
        $entities = collect([
            \App\Domain\Entities\Rol::fromArray([
                'id' => 1,
                'nombre' => 'Admin',
                'estado' => 'Activo',
                'created_by' => null,
                'updated_by' => null,
                'created_at' => '2026-01-01T00:00:00.000000Z',
                'updated_at' => '2026-01-01T00:00:00.000000Z',
            ]),
        ]);

        $resource = \App\Http\Resources\BaseResource::collection($entities);
        $response = $resource->toArray(request());

        $this->assertIsArray($response);
        $this->assertCount(1, $response);
    }
}
