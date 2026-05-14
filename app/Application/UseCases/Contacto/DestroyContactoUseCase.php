<?php

namespace App\Application\UseCases\Contacto;

use App\Domain\Repositories\ContactoRepositoryInterface;

class DestroyContactoUseCase
{
    public function __construct(
        private ContactoRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
