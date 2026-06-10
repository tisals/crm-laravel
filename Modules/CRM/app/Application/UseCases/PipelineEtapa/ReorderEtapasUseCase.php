<?php

namespace Modules\CRM\Application\UseCases\PipelineEtapa;

use Modules\CRM\Domain\Repositories\PipelineEtapaRepositoryInterface;

class ReorderEtapasUseCase
{
    public function __construct(
        private PipelineEtapaRepositoryInterface $repository,
    ) {}

    public function execute(int $pipelineId, array $orderedIds): void
    {
        $this->repository->reorder($pipelineId, $orderedIds);
    }
}
