<?php

namespace App\Domain\Entities;

class Movimiento
{
    public function __construct(
        public int $id,
        public string $fecha,
        public float $valor_debito = 0,
        public float $valor_credito = 0,
        public ?int $proveedor_id = null,
        public ?int $colaborador_id = null,
        public ?int $servicio_id = null,
        public ?string $observaciones = null,
        public ?int $created_by = null,
        public ?int $updated_by = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            fecha: $data['fecha'],
            valor_debito: $data['valor_debito'] ?? 0,
            valor_credito: $data['valor_credito'] ?? 0,
            proveedor_id: $data['proveedor_id'] ?? null,
            colaborador_id: $data['colaborador_id'] ?? null,
            servicio_id: $data['servicio_id'] ?? null,
            observaciones: $data['observaciones'] ?? null,
            created_by: $data['created_by'] ?? null,
            updated_by: $data['updated_by'] ?? null,
            created_at: $data['created_at'] ?? null,
            updated_at: $data['updated_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha,
            'valor_debito' => $this->valor_debito,
            'valor_credito' => $this->valor_credito,
            'proveedor_id' => $this->proveedor_id,
            'colaborador_id' => $this->colaborador_id,
            'servicio_id' => $this->servicio_id,
            'observaciones' => $this->observaciones,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
