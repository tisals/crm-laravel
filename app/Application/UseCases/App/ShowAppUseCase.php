<?php

namespace App\Application\UseCases\App;

use App\Domain\Repositories\AppRepositoryInterface;

class ShowAppUseCase
{
    public function __construct(
        private AppRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
