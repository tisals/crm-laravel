<?php

namespace App\Application\UseCases\Seguimiento;

use App\Domain\Repositories\SeguimientoRepositoryInterface;
use Illuminate\Support\Facades\Auth;

class StoreSeguimientoUseCase
{
    public function __construct(
        private SeguimientoRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        $data['autor_id'] = Auth::id();

        return $this->repository->create($data);
    }
}
