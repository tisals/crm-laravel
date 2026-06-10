<?php

namespace Tests\Unit\Infrastructure\Persistence;

use App\Domain\Entities\Pipeline as PipelineEntity;
use App\Domain\Repositories\PipelineRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CRM\Models\Pipeline;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EloquentPipelineRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private PipelineRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->app->make(PipelineRepositoryInterface::class);
    }

    protected function seeder(): string|false
    {
        return false;
    }

    #[Test]
    public function it_can_find_pipeline_by_id(): void
    {
        $eloquent = Pipeline::factory()->create([
            'nombre' => 'Cotización',
            'codigo' => 'COTIZACION',
        ]);

        $entity = $this->repository->find($eloquent->id);

        $this->assertInstanceOf(PipelineEntity::class, $entity);
        $this->assertEquals($eloquent->id, $entity->id);
        $this->assertEquals('Cotización', $entity->nombre);
        $this->assertEquals('COTIZACION', $entity->codigo);
        $this->assertTrue($entity->habilitado);
    }

    #[Test]
    public function it_returns_null_when_not_found(): void
    {
        $entity = $this->repository->find(99999);

        $this->assertNull($entity);
    }

    #[Test]
    public function it_can_create_pipeline(): void
    {
        $data = [
            'nombre' => 'Nuevo Pipeline',
            'codigo' => 'NUEVO',
            'habilitado' => true,
        ];

        $entity = $this->repository->create($data);

        $this->assertInstanceOf(PipelineEntity::class, $entity);
        $this->assertNotNull($entity->id);
        $this->assertEquals('Nuevo Pipeline', $entity->nombre);
        $this->assertEquals('NUEVO', $entity->codigo);
        $this->assertTrue($entity->habilitado);

        $this->assertDatabaseHas('pipelines', [
            'id' => $entity->id,
            'nombre' => 'Nuevo Pipeline',
            'codigo' => 'NUEVO',
        ]);
    }

    #[Test]
    public function it_can_update_pipeline(): void
    {
        $eloquent = Pipeline::factory()->create([
            'nombre' => 'Original',
            'codigo' => 'ORIG',
        ]);

        $entity = $this->repository->update($eloquent->id, [
            'nombre' => 'Actualizado',
            'codigo' => 'ORIG',
        ]);

        $this->assertInstanceOf(PipelineEntity::class, $entity);
        $this->assertEquals($eloquent->id, $entity->id);
        $this->assertEquals('Actualizado', $entity->nombre);
        $this->assertEquals('ORIG', $entity->codigo);

        $this->assertDatabaseHas('pipelines', [
            'id' => $eloquent->id,
            'nombre' => 'Actualizado',
        ]);
    }

    #[Test]
    public function it_can_delete_pipeline(): void
    {
        $eloquent = Pipeline::factory()->create();

        $result = $this->repository->delete($eloquent->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('pipelines', ['id' => $eloquent->id]);
    }

    #[Test]
    public function it_can_list_all_pipelines(): void
    {
        $before = count($this->repository->all());

        Pipeline::factory()->count(3)->create();

        $entities = $this->repository->all();

        $this->assertCount($before + 3, $entities);
        foreach ($entities as $entity) {
            $this->assertInstanceOf(PipelineEntity::class, $entity);
        }
    }

    #[Test]
    public function it_can_find_by_codigo(): void
    {
        Pipeline::factory()->create([
            'nombre' => 'Pipeline Test',
            'codigo' => 'TEST-001',
        ]);

        $entity = $this->repository->findByCodigo('TEST-001');

        $this->assertInstanceOf(PipelineEntity::class, $entity);
        $this->assertEquals('Pipeline Test', $entity->nombre);
        $this->assertEquals('TEST-001', $entity->codigo);

        $notFound = $this->repository->findByCodigo('NO-EXISTE');
        $this->assertNull($notFound);
    }
}
