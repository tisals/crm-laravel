<?php

namespace App\Application\UseCases\Pipeline;

use App\Domain\Entities\Pipeline;
use App\Domain\Repositories\PipelineRepositoryInterface;
use Illuminate\Validation\ValidationException;

class CreatePipelineUseCase
{
    public function __construct(
        private PipelineRepositoryInterface $repository,
    ) {}

    public function execute(array $data): Pipeline
    {
        $this->validate($data);

        return $this->repository->create($data);
    }

    private function validate(array $data): void
    {
        $errors = [];

        if (empty($data['nombre'])) {
            $errors['nombre'] = ['El nombre es requerido.'];
        }

        if (empty($data['codigo'])) {
            $errors['codigo'] = ['El código es requerido.'];
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        // Check unique codigo
        $existing = $this->repository->findByCodigo($data['codigo']);
        if ($existing) {
            throw ValidationException::withMessages([
                'codigo' => ["El código '{$data['codigo']}' ya está en uso."],
            ]);
        }
    }
}
