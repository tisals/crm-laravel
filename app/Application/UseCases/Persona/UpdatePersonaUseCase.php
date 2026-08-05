<?php

namespace App\Application\UseCases\Persona;

use App\Domain\Repositories\PersonaRepositoryInterface;

class UpdatePersonaUseCase
{
    public function __construct(
        private PersonaRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
