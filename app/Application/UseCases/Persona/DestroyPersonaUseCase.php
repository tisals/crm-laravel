<?php

namespace App\Application\UseCases\Persona;

use App\Domain\Repositories\PersonaRepositoryInterface;

class DestroyPersonaUseCase
{
    public function __construct(
        private PersonaRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
