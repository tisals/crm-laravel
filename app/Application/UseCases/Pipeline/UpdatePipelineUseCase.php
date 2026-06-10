<?php

namespace App\Application\UseCases\Pipeline;

use App\Domain\Entities\Pipeline;
use App\Domain\Repositories\PipelineRepositoryInterface;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdatePipelineUseCase
{
    public function __construct(
        private PipelineRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): Pipeline
    {
        $pipeline = $this->repository->find($id);

        if (! $pipeline) {
            throw new NotFoundHttpException('Pipeline not found');
        }

        if (isset($data['codigo'])) {
            $existing = $this->repository->findByCodigo($data['codigo']);
            if ($existing && $existing->id !== $id) {
                throw ValidationException::withMessages([
                    'codigo' => ["El código '{$data['codigo']}' ya está en uso por otro pipeline."],
                ]);
            }
        }

        return $this->repository->update($id, $data);
    }
}
