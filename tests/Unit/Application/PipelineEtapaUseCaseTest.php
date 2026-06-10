<?php

namespace Tests\Unit\Application;

use App\Domain\Entities\PipelineEtapa;
use Mockery;
use Modules\CRM\Application\UseCases\PipelineEtapa\CreateEtapaUseCase;
use Modules\CRM\Application\UseCases\PipelineEtapa\DeleteEtapaUseCase;
use Modules\CRM\Application\UseCases\PipelineEtapa\GetEtapaUseCase;
use Modules\CRM\Application\UseCases\PipelineEtapa\ListEtapasUseCase;
use Modules\CRM\Application\UseCases\PipelineEtapa\ReorderEtapasUseCase;
use Modules\CRM\Application\UseCases\PipelineEtapa\UpdateEtapaUseCase;
use Modules\CRM\Domain\Repositories\PipelineEtapaRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PipelineEtapaUseCaseTest extends TestCase
{
    private PipelineEtapaRepositoryInterface|Mockery\MockInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(PipelineEtapaRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createSampleEtapa(
        int $id = 1,
        int $pipelineId = 1,
        string $nombre = 'Borrador',
        int $orden = 1,
    ): PipelineEtapa {
        return new PipelineEtapa(
            id: $id,
            pipeline_id: $pipelineId,
            nombre: $nombre,
            orden: $orden,
            habilitado: true,
            created_at: '2026-01-01T00:00:00.000000Z',
            updated_at: '2026-01-01T00:00:00.000000Z',
        );
    }

    // ─── ListEtapasUseCase ────────────────────────────────────────────

    #[Test]
    public function list_use_case_returns_etapas_for_pipeline(): void
    {
        $etapas = [
            $this->createSampleEtapa(1, 1, 'Borrador', 1),
            $this->createSampleEtapa(2, 1, 'Cotización', 2),
        ];

        $this->repository->shouldReceive('findByPipeline')
            ->with(1)
            ->once()
            ->andReturn($etapas);

        $useCase = new ListEtapasUseCase($this->repository);
        $result = $useCase->execute(1);

        $this->assertCount(2, $result);
        $this->assertContainsOnlyInstancesOf(PipelineEtapa::class, $result);
        $this->assertEquals('Borrador', $result[0]->nombre);
    }

    #[Test]
    public function list_use_case_returns_empty_array_when_no_etapas(): void
    {
        $this->repository->shouldReceive('findByPipeline')
            ->with(99)
            ->once()
            ->andReturn([]);

        $useCase = new ListEtapasUseCase($this->repository);
        $result = $useCase->execute(99);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ─── GetEtapaUseCase ──────────────────────────────────────────────

    #[Test]
    public function get_use_case_returns_etapa_when_found(): void
    {
        $etapa = $this->createSampleEtapa();

        $this->repository->shouldReceive('find')
            ->with(1)
            ->once()
            ->andReturn($etapa);

        $useCase = new GetEtapaUseCase($this->repository);
        $result = $useCase->execute(1);

        $this->assertInstanceOf(PipelineEtapa::class, $result);
        $this->assertEquals(1, $result->id);
        $this->assertEquals('Borrador', $result->nombre);
    }

    #[Test]
    public function get_use_case_throws_not_found_when_missing(): void
    {
        $this->repository->shouldReceive('find')
            ->with(99999)
            ->once()
            ->andReturn(null);

        $useCase = new GetEtapaUseCase($this->repository);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('PipelineEtapa not found');

        $useCase->execute(99999);
    }

    // ─── CreateEtapaUseCase ───────────────────────────────────────────

    #[Test]
    public function create_use_case_creates_etapa_with_valid_data(): void
    {
        $data = [
            'pipeline_id' => 1,
            'nombre' => 'Nueva Etapa',
        ];

        $this->repository->shouldReceive('maxOrden')
            ->with(1)
            ->once()
            ->andReturn(0);

        $createData = array_merge($data, ['orden' => 1, 'habilitado' => true]);

        $this->repository->shouldReceive('create')
            ->with($createData)
            ->once()
            ->andReturn($this->createSampleEtapa(1, 1, 'Nueva Etapa', 1));

        $useCase = new CreateEtapaUseCase($this->repository);
        $result = $useCase->execute($data);

        $this->assertInstanceOf(PipelineEtapa::class, $result);
        $this->assertEquals('Nueva Etapa', $result->nombre);
        $this->assertEquals(1, $result->orden);
    }

    #[Test]
    public function create_use_case_auto_increments_orden(): void
    {
        $data = [
            'pipeline_id' => 1,
            'nombre' => 'Siguiente',
        ];

        $this->repository->shouldReceive('maxOrden')
            ->with(1)
            ->once()
            ->andReturn(3);

        $createData = array_merge($data, ['orden' => 4, 'habilitado' => true]);

        $this->repository->shouldReceive('create')
            ->with($createData)
            ->once()
            ->andReturn($this->createSampleEtapa(4, 1, 'Siguiente', 4));

        $useCase = new CreateEtapaUseCase($this->repository);
        $result = $useCase->execute($data);

        $this->assertEquals(4, $result->orden);
    }

    #[Test]
    public function create_use_case_throws_when_nombre_empty(): void
    {
        $data = [
            'pipeline_id' => 1,
            'nombre' => '',
        ];

        $useCase = new CreateEtapaUseCase($this->repository);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('El nombre es requerido');

        $useCase->execute($data);
    }

    #[Test]
    public function create_use_case_throws_when_nombre_missing(): void
    {
        $data = ['pipeline_id' => 1];

        $useCase = new CreateEtapaUseCase($this->repository);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('El nombre es requerido');

        $useCase->execute($data);
    }

    #[Test]
    public function create_use_case_throws_when_pipeline_id_missing(): void
    {
        $data = ['nombre' => 'Etapa'];

        $useCase = new CreateEtapaUseCase($this->repository);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('El pipeline_id es requerido');

        $useCase->execute($data);
    }

    // ─── UpdateEtapaUseCase ───────────────────────────────────────────

    #[Test]
    public function update_use_case_updates_etapa_with_valid_data(): void
    {
        $data = ['nombre' => 'Actualizado'];

        $this->repository->shouldReceive('find')
            ->with(1)
            ->once()
            ->andReturn($this->createSampleEtapa(1, 1, 'Original', 1));

        $this->repository->shouldReceive('update')
            ->with(1, $data)
            ->once()
            ->andReturn($this->createSampleEtapa(1, 1, 'Actualizado', 1));

        $useCase = new UpdateEtapaUseCase($this->repository);
        $result = $useCase->execute(1, $data);

        $this->assertInstanceOf(PipelineEtapa::class, $result);
        $this->assertEquals('Actualizado', $result->nombre);
    }

    #[Test]
    public function update_use_case_throws_when_etapa_not_found(): void
    {
        $this->repository->shouldReceive('find')
            ->with(99999)
            ->once()
            ->andReturn(null);

        $useCase = new UpdateEtapaUseCase($this->repository);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('PipelineEtapa not found');

        $useCase->execute(99999, ['nombre' => 'Nope']);
    }

    // ─── DeleteEtapaUseCase ───────────────────────────────────────────

    #[Test]
    public function delete_use_case_deletes_etapa(): void
    {
        $this->repository->shouldReceive('find')
            ->with(1)
            ->once()
            ->andReturn($this->createSampleEtapa());

        $this->repository->shouldReceive('delete')
            ->with(1)
            ->once()
            ->andReturn(true);

        $useCase = new DeleteEtapaUseCase($this->repository);
        $result = $useCase->execute(1);

        $this->assertTrue($result);
    }

    #[Test]
    public function delete_use_case_throws_when_etapa_not_found(): void
    {
        $this->repository->shouldReceive('find')
            ->with(99999)
            ->once()
            ->andReturn(null);

        $useCase = new DeleteEtapaUseCase($this->repository);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('PipelineEtapa not found');

        $useCase->execute(99999);
    }

    // ─── ReorderEtapasUseCase ─────────────────────────────────────────

    #[Test]
    public function reorder_use_case_reorders_etapas(): void
    {
        $orderedIds = [3, 1, 2];

        $this->repository->shouldReceive('reorder')
            ->with(1, $orderedIds)
            ->once()
            ->andReturnNull();

        $useCase = new ReorderEtapasUseCase($this->repository);
        $useCase->execute(1, $orderedIds);

        // No exception means success
        $this->expectNotToPerformAssertions();
    }
}
