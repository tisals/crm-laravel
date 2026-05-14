<?php

namespace App\Domain\Entities;

class Cuenta
{
    public function __construct(
        public int $id,
        public int $proveedor_id,
        public string $banco,
        public string $numero_cuenta,
        public string $tipo = 'Ahorros',
        public string $estado = 'Activo',
        public ?int $created_by = null,
        public ?int $updated_by = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            proveedor_id: $data['proveedor_id'],
            banco: $data['banco'],
            numero_cuenta: $data['numero_cuenta'],
            tipo: $data['tipo'] ?? 'Ahorros',
            estado: $data['estado'] ?? 'Activo',
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
            'proveedor_id' => $this->proveedor_id,
            'banco' => $this->banco,
            'numero_cuenta' => $this->numero_cuenta,
            'tipo' => $this->tipo,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
