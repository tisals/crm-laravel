<?php

namespace Modules\CRM\Application\UseCases\PipelineEtapa;

use App\Domain\Entities\PipelineEtapa;
use Modules\CRM\Domain\Repositories\PipelineEtapaRepositoryInterface;

class ListEtapasUseCase
{
    public function __construct(
        private PipelineEtapaRepositoryInterface $repository,
    ) {}

    /**
     * @return PipelineEtapa[]
     */
    public function execute(int $pipelineId): array
    {
        return $this->repository->findByPipeline($pipelineId);
    }
}
