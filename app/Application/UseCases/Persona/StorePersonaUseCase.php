<?php

namespace App\Application\UseCases\Persona;

use App\Domain\Repositories\PersonaRepositoryInterface;

class StorePersonaUseCase
{
    public function __construct(
        private PersonaRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
