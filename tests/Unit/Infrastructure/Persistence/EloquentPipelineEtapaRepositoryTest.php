<?php

namespace Tests\Unit\Infrastructure\Persistence;

use App\Domain\Entities\PipelineEtapa as PipelineEtapaEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CRM\Domain\Repositories\PipelineEtapaRepositoryInterface;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\PipelineEtapa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EloquentPipelineEtapaRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private PipelineEtapaRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->app->make(PipelineEtapaRepositoryInterface::class);
    }

    protected function seeder(): string|false
    {
        return false;
    }

    // ─── Find by ID ───────────────────────────────────────────────────

    #[Test]
    public function it_can_find_etapa_by_id(): void
    {
        $pipeline = Pipeline::factory()->create();
        $eloquent = PipelineEtapa::factory()->forPipeline($pipeline)->create([
            'nombre' => 'Borrador',
            'orden' => 1,
        ]);

        $entity = $this->repository->find($eloquent->id);

        $this->assertInstanceOf(PipelineEtapaEntity::class, $entity);
        $this->assertEquals($eloquent->id, $entity->id);
        $this->assertEquals($pipeline->id, $entity->pipeline_id);
        $this->assertEquals('Borrador', $entity->nombre);
        $this->assertEquals(1, $entity->orden);
        $this->assertTrue($entity->habilitado);
    }

    #[Test]
    public function it_returns_null_when_not_found(): void
    {
        $entity = $this->repository->find(99999);

        $this->assertNull($entity);
    }

    // ─── Create ───────────────────────────────────────────────────────

    #[Test]
    public function it_can_create_etapa(): void
    {
        $pipeline = Pipeline::factory()->create();

        $data = [
            'pipeline_id' => $pipeline->id,
            'nombre' => 'Nueva Etapa',
            'orden' => 1,
            'habilitado' => true,
        ];

        $entity = $this->repository->create($data);

        $this->assertInstanceOf(PipelineEtapaEntity::class, $entity);
        $this->assertNotNull($entity->id);
        $this->assertEquals($pipeline->id, $entity->pipeline_id);
        $this->assertEquals('Nueva Etapa', $entity->nombre);
        $this->assertEquals(1, $entity->orden);
        $this->assertTrue($entity->habilitado);

        $this->assertDatabaseHas('pipeline_etapas', [
            'id' => $entity->id,
            'nombre' => 'Nueva Etapa',
        ]);
    }

    // ─── Update ───────────────────────────────────────────────────────

    #[Test]
    public function it_can_update_etapa(): void
    {
        $pipeline = Pipeline::factory()->create();
        $eloquent = PipelineEtapa::factory()->forPipeline($pipeline)->create([
            'nombre' => 'Original',
            'orden' => 1,
        ]);

        $entity = $this->repository->update($eloquent->id, [
            'nombre' => 'Actualizado',
            'orden' => 2,
        ]);

        $this->assertInstanceOf(PipelineEtapaEntity::class, $entity);
        $this->assertEquals($eloquent->id, $entity->id);
        $this->assertEquals('Actualizado', $entity->nombre);
        $this->assertEquals(2, $entity->orden);

        $this->assertDatabaseHas('pipeline_etapas', [
            'id' => $eloquent->id,
            'nombre' => 'Actualizado',
            'orden' => 2,
        ]);
    }

    // ─── Delete ───────────────────────────────────────────────────────

    #[Test]
    public function it_can_delete_etapa(): void
    {
        $pipeline = Pipeline::factory()->create();
        $eloquent = PipelineEtapa::factory()->forPipeline($pipeline)->create();

        $result = $this->repository->delete($eloquent->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('pipeline_etapas', ['id' => $eloquent->id]);
    }

    // ─── List by Pipeline ─────────────────────────────────────────────

    #[Test]
    public function it_can_list_etapas_by_pipeline(): void
    {
        $pipeline1 = Pipeline::factory()->create();
        $pipeline2 = Pipeline::factory()->create();

        PipelineEtapa::factory()->forPipeline($pipeline1)->count(3)->create();
        PipelineEtapa::factory()->forPipeline($pipeline2)->count(2)->create();

        $etapas = $this->repository->findByPipeline($pipeline1->id);

        $this->assertCount(3, $etapas);
        foreach ($etapas as $etapa) {
            $this->assertInstanceOf(PipelineEtapaEntity::class, $etapa);
            $this->assertEquals($pipeline1->id, $etapa->pipeline_id);
        }
    }

    // ─── Reorder ──────────────────────────────────────────────────────

    #[Test]
    public function it_can_reorder_etapas(): void
    {
        $pipeline = Pipeline::factory()->create();
        $etapa1 = PipelineEtapa::factory()->forPipeline($pipeline)->atOrden(1)->create();
        $etapa2 = PipelineEtapa::factory()->forPipeline($pipeline)->atOrden(2)->create();
        $etapa3 = PipelineEtapa::factory()->forPipeline($pipeline)->atOrden(3)->create();

        $this->repository->reorder($pipeline->id, [$etapa3->id, $etapa1->id, $etapa2->id]);

        $this->assertDatabaseHas('pipeline_etapas', ['id' => $etapa3->id, 'orden' => 1]);
        $this->assertDatabaseHas('pipeline_etapas', ['id' => $etapa1->id, 'orden' => 2]);
        $this->assertDatabaseHas('pipeline_etapas', ['id' => $etapa2->id, 'orden' => 3]);
    }

    #[Test]
    public function it_reorder_validates_cross_pipeline(): void
    {
        $pipeline1 = Pipeline::factory()->create();
        $pipeline2 = Pipeline::factory()->create();

        $etapa1 = PipelineEtapa::factory()->forPipeline($pipeline1)->create();
        $etapa2 = PipelineEtapa::factory()->forPipeline($pipeline2)->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('All etapas must belong to the same pipeline');

        $this->repository->reorder($pipeline1->id, [$etapa1->id, $etapa2->id]);
    }

    // ─── Max Orden ────────────────────────────────────────────────────

    #[Test]
    public function it_can_get_max_orden(): void
    {
        $pipeline = Pipeline::factory()->create();

        PipelineEtapa::factory()->forPipeline($pipeline)->atOrden(5)->create();
        PipelineEtapa::factory()->forPipeline($pipeline)->atOrden(3)->create();

        $max = $this->repository->maxOrden($pipeline->id);

        $this->assertEquals(5, $max);
    }

    #[Test]
    public function it_returns_zero_when_no_etapas_for_max_orden(): void
    {
        $pipeline = Pipeline::factory()->create();

        $max = $this->repository->maxOrden($pipeline->id);

        $this->assertEquals(0, $max);
    }
}
