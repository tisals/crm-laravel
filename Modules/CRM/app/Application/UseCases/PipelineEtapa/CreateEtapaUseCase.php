<?php

namespace Modules\CRM\Application\UseCases\PipelineEtapa;

use App\Domain\Entities\PipelineEtapa;
use Modules\CRM\Domain\Repositories\PipelineEtapaRepositoryInterface;

class CreateEtapaUseCase
{
    public function __construct(
        private PipelineEtapaRepositoryInterface $repository,
    ) {}

    public function execute(array $data): PipelineEtapa
    {
        $this->validate($data);

        // Auto-increment orden
        $maxOrden = $this->repository->maxOrden($data['pipeline_id']);
        $data['orden'] = $maxOrden + 1;
        $data['habilitado'] = $data['habilitado'] ?? true;

        return $this->repository->create($data);
    }

    private function validate(array $data): void
    {
        if (! isset($data['pipeline_id']) || empty($data['pipeline_id'])) {
            throw new \InvalidArgumentException('El pipeline_id es requerido');
        }

        if (! isset($data['nombre']) || empty(trim($data['nombre']))) {
            throw new \InvalidArgumentException('El nombre es requerido');
        }
    }
}
