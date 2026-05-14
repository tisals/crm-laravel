<?php

namespace App\Application\UseCases\Usuario;

use App\Domain\Repositories\UsuarioRepositoryInterface;
use Exception;

class DestroyUsuarioUseCase
{
    public function __construct(
        private UsuarioRepositoryInterface $repository,
    ) {}

    public function execute(int $id, int $currentUserId): bool
    {
        if ($id === $currentUserId) {
            throw new Exception('No puedes eliminar tu propio usuario.');
        }

        return $this->repository->delete($id);
    }
}
