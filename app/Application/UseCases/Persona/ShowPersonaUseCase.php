<?php

namespace App\Application\UseCases\Persona;

use App\Domain\Repositories\PersonaRepositoryInterface;

class ShowPersonaUseCase
{
    public function __construct(
        private PersonaRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
