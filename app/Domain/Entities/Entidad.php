<?php

namespace App\Domain\Entities;

class Entidad
{
    public function __construct(
        public int $id,
        public string $tipo_persona,
        public ?string $tipo_id = null,
        public ?string $identificacion = null,
        public string $nombre,
        public ?string $nombre_comercial = null,
        public ?string $direccion = null,
        public ?string $ciudad_cod = null,
        public ?string $dominio = null,
        public ?string $rut = null,
        public ?string $logo = null,
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
            tipo_persona: $data['tipo_persona'],
            tipo_id: $data['tipo_id'] ?? null,
            identificacion: $data['identificacion'] ?? null,
            nombre: $data['nombre'],
            nombre_comercial: $data['nombre_comercial'] ?? null,
            direccion: $data['direccion'] ?? null,
            ciudad_cod: $data['ciudad_cod'] ?? null,
            dominio: $data['dominio'] ?? null,
            rut: $data['rut'] ?? null,
            logo: $data['logo'] ?? null,
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
            'tipo_persona' => $this->tipo_persona,
            'tipo_id' => $this->tipo_id,
            'identificacion' => $this->identificacion ?? '',
            'nombre' => $this->nombre,
            'nombre_comercial' => $this->nombre_comercial,
            'direccion' => $this->direccion,
            'ciudad_cod' => $this->ciudad_cod,
            'dominio' => $this->dominio,
            'rut' => $this->rut,
            'logo' => $this->logo,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
