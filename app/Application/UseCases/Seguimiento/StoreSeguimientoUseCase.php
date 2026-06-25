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
        // Always override caller-provided values with the authenticated user.
        // This guarantees that autor_id and created_by always reflect who
        // actually created the seguimiento, never a forged client value.
        $userId = Auth::id();
        $data['autor_id']   = $userId;
        $data['created_by'] = $userId;

        return $this->repository->create($data);
    }
}
