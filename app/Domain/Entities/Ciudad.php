<?php

namespace App\Domain\Entities;

class Ciudad
{
    public function __construct(
        public string $cod_municipio,
        public string $nombre,
        public string $departamento,
        public ?int $created_by = null,
        public ?int $updated_by = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            cod_municipio: $data['cod_municipio'],
            nombre: $data['nombre'],
            departamento: $data['departamento'],
            created_by: $data['created_by'] ?? null,
            updated_by: $data['updated_by'] ?? null,
            created_at: $data['created_at'] ?? null,
            updated_at: $data['updated_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'cod_municipio' => $this->cod_municipio,
            'nombre' => $this->nombre,
            'departamento' => $this->departamento,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
