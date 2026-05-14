<?php

namespace App\Application\UseCases\Contacto;

use App\Domain\Repositories\ContactoRepositoryInterface;

class StoreContactoUseCase
{
    public function __construct(
        private ContactoRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
