<?php

namespace App\Domain\Entities;

class Seguimiento
{
    public function __construct(
        public int $id,
        public ?int $oportunidad_id = null,
        public ?int $contacto_id = null,
        public ?int $entidad_id = null,
        public string $tipo,
        public string $fecha,
        public ?string $hora = null,
        public ?string $fecha_fin = null,
        public ?string $notas = null,
        public ?int $autor_id = null,
        public string $estado = 'Pendiente',
        public ?int $created_by = null,
        public ?int $updated_by = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            oportunidad_id: $data['oportunidad_id'] ?? null,
            contacto_id: $data['contacto_id'] ?? null,
            entidad_id: $data['entidad_id'] ?? null,
            tipo: $data['tipo'],
            fecha: $data['fecha'],
            hora: $data['hora'] ?? null,
            fecha_fin: $data['fecha_fin'] ?? null,
            notas: $data['notas'] ?? null,
            autor_id: $data['autor_id'] ?? null,
            estado: $data['estado'] ?? 'Pendiente',
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
            'oportunidad_id' => $this->oportunidad_id,
            'contacto_id' => $this->contacto_id,
            'entidad_id' => $this->entidad_id,
            'tipo' => $this->tipo,
            'fecha' => $this->fecha,
            'hora' => $this->hora,
            'fecha_fin' => $this->fecha_fin,
            'notas' => $this->notas,
            'autor_id' => $this->autor_id,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
