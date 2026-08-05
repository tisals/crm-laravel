<?php

namespace App\Application\UseCases\App;

use App\Domain\Repositories\AppRepositoryInterface;

class DestroyAppUseCase
{
    public function __construct(
        private AppRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
