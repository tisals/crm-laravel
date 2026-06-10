<?php

namespace App\Application\UseCases\Pipeline;

use App\Domain\Entities\Pipeline;
use App\Domain\Repositories\PipelineRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GetPipelineUseCase
{
    public function __construct(
        private PipelineRepositoryInterface $repository,
    ) {}

    public function execute(int $id): Pipeline
    {
        $pipeline = $this->repository->find($id);

        if (! $pipeline) {
            throw new NotFoundHttpException('Pipeline not found');
        }

        return $pipeline;
    }
}
