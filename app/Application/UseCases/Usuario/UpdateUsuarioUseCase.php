<?php

namespace App\Application\UseCases\Usuario;

use App\Domain\Repositories\UsuarioRepositoryInterface;

class UpdateUsuarioUseCase
{
    public function __construct(
        private UsuarioRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        if (isset($data['password'])) {
            $data['password_hash'] = bcrypt($data['password']);
            unset($data['password']);
        }

        return $this->repository->update($id, $data);
    }
}
