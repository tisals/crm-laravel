<?php

namespace App\Domain\Entities;

/**
 * Domain DTO for a per-(user, app) scoped permission. Mirrors the Eloquent
 * model `App\Models\UsuarioAppPermiso` but is a pure PHP value object — no
 * framework dependencies, safe to pass through use case boundaries.
 *
 * Used by the permission-related use cases (Grant/Revoke/Sync) and by the
 * repository layer to return results to upper layers.
 */
class UsuarioAppPermiso
{
    public function __construct(
        public int $id,
        public int $usuario_id,
        public int $app_id,
        public string $vista,
        public ?int $created_by = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
        public ?string $deleted_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            usuario_id: (int) $data['usuario_id'],
            app_id: (int) $data['app_id'],
            vista: $data['vista'],
            created_by: isset($data['created_by']) ? (int) $data['created_by'] : null,
            created_at: $data['created_at'] ?? null,
            updated_at: $data['updated_at'] ?? null,
            deleted_at: $data['deleted_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'usuario_id' => $this->usuario_id,
            'app_id' => $this->app_id,
            'vista' => $this->vista,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
