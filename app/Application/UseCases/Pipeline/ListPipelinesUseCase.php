<?php

namespace App\Application\UseCases\Pipeline;

use App\Domain\Repositories\PipelineRepositoryInterface;

class ListPipelinesUseCase
{
    public function __construct(
        private PipelineRepositoryInterface $repository,
    ) {}

    public function execute(): array
    {
        return $this->repository->all();
    }
}
