<?php

namespace App\Application\Services;

use App\Domain\Repositories\PermisoRepositoryInterface;

class RbacService
{
    public function __construct(
        private PermisoRepositoryInterface $permisoRepository,
    ) {}

    public function hasPermission(int $rolId, string $vista): bool
    {
        return $this->permisoRepository->hasPermissionForRol($rolId, $vista);
    }
}
