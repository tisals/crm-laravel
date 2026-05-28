<?php

namespace App\Domain\Entities;

class Maestro
{
    public function __construct(
        public int $id,
        public string $nombre,
        public string $campo,
        public string $habilitado,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            nombre: $data['nombre'] ?? '',
            campo: $data['campo'] ?? '',
            habilitado: $data['habilitado'] ?? 'Y',
            created_at: $data['created_at'] ?? null,
            updated_at: $data['updated_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'campo' => $this->campo,
            'habilitado' => $this->habilitado,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
