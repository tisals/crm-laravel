<?php

namespace Tests\Unit\Application;

use App\Application\UseCases\Pipeline\CreatePipelineUseCase;
use App\Application\UseCases\Pipeline\DeletePipelineUseCase;
use App\Application\UseCases\Pipeline\GetPipelineUseCase;
use App\Application\UseCases\Pipeline\ListPipelinesUseCase;
use App\Application\UseCases\Pipeline\UpdatePipelineUseCase;
use App\Domain\Entities\Pipeline;
use App\Domain\Repositories\PipelineRepositoryInterface;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PipelineUseCaseTest extends TestCase
{
    private PipelineRepositoryInterface|Mockery\MockInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(PipelineRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createSamplePipeline(int $id = 1, string $nombre = 'Cotización', string $codigo = 'COTIZACION'): Pipeline
    {
        return new Pipeline(
            id: $id,
            nombre: $nombre,
            codigo: $codigo,
            habilitado: true,
            etapas: [],
            created_at: '2026-01-01T00:00:00.000000Z',
            updated_at: '2026-01-01T00:00:00.000000Z',
        );
    }

    // ─── ListPipelinesUseCase ─────────────────────────────────────────

    #[Test]
    public function list_use_case_returns_all_pipelines(): void
    {
        $pipelines = [
            $this->createSamplePipeline(1, 'Pipeline A', 'PA'),
            $this->createSamplePipeline(2, 'Pipeline B', 'PB'),
        ];

        $this->repository->shouldReceive('all')
            ->once()
            ->andReturn($pipelines);

        $useCase = new ListPipelinesUseCase($this->repository);
        $result = $useCase->execute();

        $this->assertCount(2, $result);
        $this->assertContainsOnlyInstancesOf(Pipeline::class, $result);
        $this->assertEquals('Pipeline A', $result[0]->nombre);
    }

    #[Test]
    public function list_use_case_returns_empty_array_when_no_pipelines(): void
    {
        $this->repository->shouldReceive('all')
            ->once()
            ->andReturn([]);

        $useCase = new ListPipelinesUseCase($this->repository);
        $result = $useCase->execute();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ─── GetPipelineUseCase ───────────────────────────────────────────

    #[Test]
    public function get_use_case_returns_pipeline_when_found(): void
    {
        $pipeline = $this->createSamplePipeline();

        $this->repository->shouldReceive('find')
            ->with(1)
            ->once()
            ->andReturn($pipeline);

        $useCase = new GetPipelineUseCase($this->repository);
        $result = $useCase->execute(1);

        $this->assertInstanceOf(Pipeline::class, $result);
        $this->assertEquals(1, $result->id);
        $this->assertEquals('Cotización', $result->nombre);
    }

    #[Test]
    public function get_use_case_throws_not_found_when_missing(): void
    {
        $this->repository->shouldReceive('find')
            ->with(99999)
            ->once()
            ->andReturn(null);

        $useCase = new GetPipelineUseCase($this->repository);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Pipeline not found');

        $useCase->execute(99999);
    }

    // ─── CreatePipelineUseCase ────────────────────────────────────────

    #[Test]
    public function create_use_case_creates_pipeline_with_valid_data(): void
    {
        $data = ['nombre' => 'Nuevo', 'codigo' => 'NUEVO'];

        $this->repository->shouldReceive('findByCodigo')
            ->with('NUEVO')
            ->once()
            ->andReturn(null);

        $this->repository->shouldReceive('create')
            ->with($data)
            ->once()
            ->andReturn($this->createSamplePipeline(1, 'Nuevo', 'NUEVO'));

        $useCase = new CreatePipelineUseCase($this->repository);
        $result = $useCase->execute($data);

        $this->assertInstanceOf(Pipeline::class, $result);
        $this->assertEquals('Nuevo', $result->nombre);
    }

    #[Test]
    public function create_use_case_throws_when_nombre_empty(): void
    {
        $data = ['nombre' => '', 'codigo' => 'NUEVO'];

        $useCase = new CreatePipelineUseCase($this->repository);

        $this->expectException(ValidationException::class);

        $useCase->execute($data);
    }

    #[Test]
    public function create_use_case_throws_when_codigo_empty(): void
    {
        $data = ['nombre' => 'Nuevo', 'codigo' => ''];

        $useCase = new CreatePipelineUseCase($this->repository);

        $this->expectException(ValidationException::class);

        $useCase->execute($data);
    }

    #[Test]
    public function create_use_case_throws_when_codigo_is_duplicate(): void
    {
        $data = ['nombre' => 'Nuevo', 'codigo' => 'DUPLICADO'];

        $this->repository->shouldReceive('findByCodigo')
            ->with('DUPLICADO')
            ->once()
            ->andReturn($this->createSamplePipeline(1, 'Existente', 'DUPLICADO'));

        $useCase = new CreatePipelineUseCase($this->repository);

        $this->expectException(ValidationException::class);

        $useCase->execute($data);
    }

    // ─── UpdatePipelineUseCase ────────────────────────────────────────

    #[Test]
    public function update_use_case_updates_pipeline_with_valid_data(): void
    {
        $data = ['nombre' => 'Actualizado'];

        $this->repository->shouldReceive('find')
            ->with(1)
            ->once()
            ->andReturn($this->createSamplePipeline(1, 'Original', 'ORIG'));

        $this->repository->shouldReceive('update')
            ->with(1, $data)
            ->once()
            ->andReturn($this->createSamplePipeline(1, 'Actualizado', 'ORIG'));

        $useCase = new UpdatePipelineUseCase($this->repository);
        $result = $useCase->execute(1, $data);

        $this->assertInstanceOf(Pipeline::class, $result);
        $this->assertEquals('Actualizado', $result->nombre);
    }

    #[Test]
    public function update_use_case_throws_when_pipeline_not_found(): void
    {
        $this->repository->shouldReceive('find')
            ->with(99999)
            ->once()
            ->andReturn(null);

        $useCase = new UpdatePipelineUseCase($this->repository);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Pipeline not found');

        $useCase->execute(99999, ['nombre' => 'Nope']);
    }

    #[Test]
    public function update_use_case_throws_when_codigo_is_duplicate(): void
    {
        $data = ['codigo' => 'DUPLICADO'];

        $this->repository->shouldReceive('find')
            ->with(1)
            ->once()
            ->andReturn($this->createSamplePipeline(1, 'Original', 'ORIG'));

        $this->repository->shouldReceive('findByCodigo')
            ->with('DUPLICADO')
            ->once()
            ->andReturn($this->createSamplePipeline(2, 'Otro', 'DUPLICADO'));

        $useCase = new UpdatePipelineUseCase($this->repository);

        $this->expectException(ValidationException::class);

        $useCase->execute(1, $data);
    }

    #[Test]
    public function update_use_case_allows_same_codigo_when_updating_same_pipeline(): void
    {
        $data = ['codigo' => 'ORIG'];

        $this->repository->shouldReceive('find')
            ->with(1)
            ->once()
            ->andReturn($this->createSamplePipeline(1, 'Original', 'ORIG'));

        // findByCodigo returns the SAME pipeline, which is OK (not a duplicate)
        $this->repository->shouldReceive('findByCodigo')
            ->with('ORIG')
            ->once()
            ->andReturn($this->createSamplePipeline(1, 'Original', 'ORIG'));

        $this->repository->shouldReceive('update')
            ->with(1, $data)
            ->once()
            ->andReturn($this->createSamplePipeline(1, 'Original', 'ORIG'));

        $useCase = new UpdatePipelineUseCase($this->repository);
        $result = $useCase->execute(1, $data);

        $this->assertInstanceOf(Pipeline::class, $result);
    }

    // ─── DeletePipelineUseCase ────────────────────────────────────────

    #[Test]
    public function delete_use_case_deletes_pipeline(): void
    {
        $this->repository->shouldReceive('find')
            ->with(1)
            ->once()
            ->andReturn($this->createSamplePipeline());

        $this->repository->shouldReceive('delete')
            ->with(1)
            ->once()
            ->andReturn(true);

        $useCase = new DeletePipelineUseCase($this->repository);
        $result = $useCase->execute(1);

        $this->assertTrue($result);
    }

    #[Test]
    public function delete_use_case_throws_when_pipeline_not_found(): void
    {
        $this->repository->shouldReceive('find')
            ->with(99999)
            ->once()
            ->andReturn(null);

        $useCase = new DeletePipelineUseCase($this->repository);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Pipeline not found');

        $useCase->execute(99999);
    }
}
