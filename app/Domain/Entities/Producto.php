<?php

namespace App\Domain\Entities;

class Producto
{
    public function __construct(
        public int $id,
        public string $nombre,
        public ?string $linea_negocio = null,
        public float $iva = 19.0,
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
            nombre: $data['nombre'],
            linea_negocio: $data['linea_negocio'] ?? null,
            iva: $data['iva'] ?? 19.0,
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
            'nombre' => $this->nombre,
            'linea_negocio' => $this->linea_negocio,
            'iva' => $this->iva,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
