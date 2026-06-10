<?php

namespace Modules\CRM\Application\UseCases\PipelineEtapa;

use Modules\CRM\Domain\Repositories\PipelineEtapaRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteEtapaUseCase
{
    public function __construct(
        private PipelineEtapaRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        $existing = $this->repository->find($id);

        if (! $existing) {
            throw new NotFoundHttpException('PipelineEtapa not found');
        }

        return $this->repository->delete($id);
    }
}
