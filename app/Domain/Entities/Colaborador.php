<?php

namespace App\Domain\Entities;

class Colaborador
{
    public function __construct(
        public int $id,
        public string $nombres,
        public string $apellidos,
        public ?int $usuario_id = null,
        public ?string $tipo_id = null,
        public ?string $identificacion = null,
        public ?string $cargo = null,
        public ?string $area = null,
        public ?string $fecha_ingreso = null,
        public ?string $fecha_retiro = null,
        public ?string $contrato = null,
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
            nombres: $data['nombres'],
            apellidos: $data['apellidos'],
            usuario_id: $data['usuario_id'] ?? null,
            tipo_id: $data['tipo_id'] ?? null,
            identificacion: $data['identificacion'] ?? null,
            cargo: $data['cargo'] ?? null,
            area: $data['area'] ?? null,
            fecha_ingreso: $data['fecha_ingreso'] ?? null,
            fecha_retiro: $data['fecha_retiro'] ?? null,
            contrato: $data['contrato'] ?? null,
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
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'usuario_id' => $this->usuario_id,
            'tipo_id' => $this->tipo_id,
            'identificacion' => $this->identificacion,
            'cargo' => $this->cargo,
            'area' => $this->area,
            'fecha_ingreso' => $this->fecha_ingreso,
            'fecha_retiro' => $this->fecha_retiro,
            'contrato' => $this->contrato,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
