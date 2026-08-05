<?php

namespace App\Domain\Entities;

class App
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $nombre,
        public string $tipo = 'internal',
        public string $auth_type = 'sanctum',
        public bool $activo = true,
        public ?string $descripcion = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            slug: $data['slug'],
            nombre: $data['nombre'],
            tipo: $data['tipo'] ?? 'internal',
            auth_type: $data['auth_type'] ?? 'sanctum',
            activo: (bool) ($data['activo'] ?? true),
            descripcion: $data['descripcion'] ?? null,
            created_at: $data['created_at'] ?? null,
            updated_at: $data['updated_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo,
            'auth_type' => $this->auth_type,
            'activo' => $this->activo,
            'descripcion' => $this->descripcion,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
