<?php

namespace Modules\CRM\Application\UseCases\PipelineEtapa;

use App\Domain\Entities\PipelineEtapa;
use Modules\CRM\Domain\Repositories\PipelineEtapaRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateEtapaUseCase
{
    public function __construct(
        private PipelineEtapaRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): PipelineEtapa
    {
        $existing = $this->repository->find($id);

        if (! $existing) {
            throw new NotFoundHttpException('PipelineEtapa not found');
        }

        return $this->repository->update($id, $data);
    }
}
