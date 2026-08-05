<?php

namespace App\Application\UseCases\App;

use App\Domain\Repositories\AppRepositoryInterface;

class UpdateAppUseCase
{
    public function __construct(
        private AppRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
