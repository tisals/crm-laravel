<?php

namespace App\Domain\Entities;

class PipelineEtapa
{
    public function __construct(
        public int $id,
        public int $pipeline_id,
        public string $nombre,
        public int $orden = 0,
        public bool $habilitado = true,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            pipeline_id: (int) $data['pipeline_id'],
            nombre: $data['nombre'],
            orden: (int) ($data['orden'] ?? 0),
            habilitado: (bool) ($data['habilitado'] ?? true),
            created_at: $data['created_at'] ?? null,
            updated_at: $data['updated_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'pipeline_id' => $this->pipeline_id,
            'nombre' => $this->nombre,
            'orden' => $this->orden,
            'habilitado' => $this->habilitado,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
