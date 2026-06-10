<?php

namespace App\Application\UseCases\Pipeline;

use App\Domain\Repositories\PipelineRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeletePipelineUseCase
{
    public function __construct(
        private PipelineRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        $pipeline = $this->repository->find($id);

        if (! $pipeline) {
            throw new NotFoundHttpException('Pipeline not found');
        }

        return $this->repository->delete($id);
    }
}
