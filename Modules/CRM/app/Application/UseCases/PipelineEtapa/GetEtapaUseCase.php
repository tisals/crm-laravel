<?php

namespace Modules\CRM\Application\UseCases\PipelineEtapa;

use App\Domain\Entities\PipelineEtapa;
use Modules\CRM\Domain\Repositories\PipelineEtapaRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GetEtapaUseCase
{
    public function __construct(
        private PipelineEtapaRepositoryInterface $repository,
    ) {}

    public function execute(int $id): PipelineEtapa
    {
        $etapa = $this->repository->find($id);

        if (! $etapa) {
            throw new NotFoundHttpException('PipelineEtapa not found');
        }

        return $etapa;
    }
}
