<?php

namespace App\Application\UseCases\Usuario;

use App\Domain\Repositories\UsuarioRepositoryInterface;

class StoreUsuarioUseCase
{
    public function __construct(
        private UsuarioRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        $data['password_hash'] = bcrypt($data['password']);
        unset($data['password']);

        return $this->repository->create($data);
    }
}
