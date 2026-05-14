<?php

namespace App\Domain\Entities;

class OrdenServicio
{
    public function __construct(
        public int $id,
        public ?int $detalle_srv_id = null,
        public ?int $colaborador_id = null,
        public ?int $proveedor_id = null,
        public ?int $contacto_id = null,
        public ?string $descripcion = null,
        public ?string $objetivo = null,
        public ?string $ubicacion = null,
        public ?string $fecha_desde = null,
        public ?string $fecha_hasta = null,
        public float $valor = 0,
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
            detalle_srv_id: $data['detalle_srv_id'] ?? null,
            colaborador_id: $data['colaborador_id'] ?? null,
            proveedor_id: $data['proveedor_id'] ?? null,
            contacto_id: $data['contacto_id'] ?? null,
            descripcion: $data['descripcion'] ?? null,
            objetivo: $data['objetivo'] ?? null,
            ubicacion: $data['ubicacion'] ?? null,
            fecha_desde: $data['fecha_desde'] ?? null,
            fecha_hasta: $data['fecha_hasta'] ?? null,
            valor: $data['valor'] ?? 0,
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
            'detalle_srv_id' => $this->detalle_srv_id,
            'colaborador_id' => $this->colaborador_id,
            'proveedor_id' => $this->proveedor_id,
            'contacto_id' => $this->contacto_id,
            'descripcion' => $this->descripcion,
            'objetivo' => $this->objetivo,
            'ubicacion' => $this->ubicacion,
            'fecha_desde' => $this->fecha_desde,
            'fecha_hasta' => $this->fecha_hasta,
            'valor' => $this->valor,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
