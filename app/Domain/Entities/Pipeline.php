<?php

namespace App\Domain\Entities;

class Pipeline
{
    public function __construct(
        public int $id,
        public string $nombre,
        public string $codigo,
        public bool $habilitado = true,
        public array $etapas = [],
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            nombre: $data['nombre'],
            codigo: $data['codigo'],
            habilitado: (bool) ($data['habilitado'] ?? true),
            etapas: $data['etapas'] ?? [],
            created_at: $data['created_at'] ?? null,
            updated_at: $data['updated_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'codigo' => $this->codigo,
            'habilitado' => $this->habilitado,
            'etapas' => $this->etapas,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
