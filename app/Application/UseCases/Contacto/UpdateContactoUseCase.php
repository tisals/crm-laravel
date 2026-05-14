<?php

namespace App\Application\UseCases\Contacto;

use App\Domain\Repositories\ContactoRepositoryInterface;

class UpdateContactoUseCase
{
    public function __construct(
        private ContactoRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
