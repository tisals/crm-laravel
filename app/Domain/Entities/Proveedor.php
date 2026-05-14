<?php

namespace App\Domain\Entities;

class Proveedor
{
    public function __construct(
        public int $id,
        public ?string $tipo_id = null,
        public string $identificacion,
        public ?string $nombres = null,
        public ?string $apellidos = null,
        public ?string $profesion = null,
        public ?string $especialidad = null,
        public ?float $iva = null,
        public ?float $retenciones = null,
        public ?string $ciudad_cod = null,
        public ?string $fecha_registro = null,
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
            tipo_id: $data['tipo_id'] ?? null,
            identificacion: $data['identificacion'],
            nombres: $data['nombres'] ?? null,
            apellidos: $data['apellidos'] ?? null,
            profesion: $data['profesion'] ?? null,
            especialidad: $data['especialidad'] ?? null,
            iva: $data['iva'] ?? null,
            retenciones: $data['retenciones'] ?? null,
            ciudad_cod: $data['ciudad_cod'] ?? null,
            fecha_registro: $data['fecha_registro'] ?? null,
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
            'tipo_id' => $this->tipo_id,
            'identificacion' => $this->identificacion,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'profesion' => $this->profesion,
            'especialidad' => $this->especialidad,
            'iva' => $this->iva,
            'retenciones' => $this->retenciones,
            'ciudad_cod' => $this->ciudad_cod,
            'fecha_registro' => $this->fecha_registro,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
