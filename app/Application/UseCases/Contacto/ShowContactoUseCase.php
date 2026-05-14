<?php

namespace App\Application\UseCases\Contacto;

use App\Domain\Repositories\ContactoRepositoryInterface;

class ShowContactoUseCase
{
    public function __construct(
        private ContactoRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
