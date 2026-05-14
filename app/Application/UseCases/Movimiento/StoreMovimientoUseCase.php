<?php

namespace App\Application\UseCases\Movimiento;

use App\Domain\Repositories\MovimientoRepositoryInterface;

class StoreMovimientoUseCase
{
    public function __construct(
        private MovimientoRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        $debito = (float) ($data['valor_debito'] ?? 0);
        $credito = (float) ($data['valor_credito'] ?? 0);

        if ($debito <= 0 && $credito <= 0) {
            throw new \InvalidArgumentException('Debe especificar un valor de débito o crédito mayor a cero.');
        }

        if ($debito > 0 && $credito > 0) {
            throw new \InvalidArgumentException('No puede especificar débito y crédito simultáneamente.');
        }

        return $this->repository->create($data);
    }
}
