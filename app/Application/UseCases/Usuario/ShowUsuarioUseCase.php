<?php

namespace App\Application\UseCases\Usuario;

use App\Domain\Repositories\UsuarioRepositoryInterface;

class ShowUsuarioUseCase
{
    public function __construct(
        private UsuarioRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
