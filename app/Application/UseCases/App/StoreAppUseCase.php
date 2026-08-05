<?php

namespace App\Application\UseCases\App;

use App\Domain\Repositories\AppRepositoryInterface;

class StoreAppUseCase
{
    public function __construct(
        private AppRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
