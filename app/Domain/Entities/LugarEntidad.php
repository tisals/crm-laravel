<?php

namespace App\Domain\Entities;

class LugarEntidad
{
    public function __construct(
        public int $id,
        public int $entidad_id,
        public ?string $area_oficina = null,
        public ?string $direccion = null,
        public ?string $direccion_adicional = null,
        public ?string $ciudad_cod = null,
        public ?int $contacto_id = null,
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
            entidad_id: $data['entidad_id'],
            area_oficina: $data['area_oficina'] ?? null,
            direccion: $data['direccion'] ?? null,
            direccion_adicional: $data['direccion_adicional'] ?? null,
            ciudad_cod: $data['ciudad_cod'] ?? null,
            contacto_id: $data['contacto_id'] ?? null,
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
            'entidad_id' => $this->entidad_id,
            'area_oficina' => $this->area_oficina,
            'direccion' => $this->direccion,
            'direccion_adicional' => $this->direccion_adicional,
            'ciudad_cod' => $this->ciudad_cod,
            'contacto_id' => $this->contacto_id,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
