<?php

namespace App\Domain\Entities;

class Oportunidad
{
    public function __construct(
        public int $id,
        public string $codigo,
        public int $entidad_id,
        public ?int $contacto_id = null,
        public string $fecha,
        public ?string $fuente_canal = null,
        public string $estado = 'Borrador',
        public ?string $observaciones = null,
        public ?string $aclaraciones = null,
        public ?int $validez_oferta = null,
        public ?string $tiempo_entrega = null,
        public ?string $forma_pago = null,
        public ?string $garantia = null,
        public ?int $created_by = null,
        public ?int $updated_by = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            codigo: $data['codigo'],
            entidad_id: $data['entidad_id'],
            contacto_id: $data['contacto_id'] ?? null,
            fecha: $data['fecha'],
            fuente_canal: $data['fuente_canal'] ?? null,
            estado: $data['estado'] ?? 'Borrador',
            observaciones: $data['observaciones'] ?? null,
            aclaraciones: $data['aclaraciones'] ?? null,
            validez_oferta: $data['validez_oferta'] ?? null,
            tiempo_entrega: $data['tiempo_entrega'] ?? null,
            forma_pago: $data['forma_pago'] ?? null,
            garantia: $data['garantia'] ?? null,
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
            'codigo' => $this->codigo,
            'entidad_id' => $this->entidad_id,
            'contacto_id' => $this->contacto_id,
            'fecha' => $this->fecha,
            'fuente_canal' => $this->fuente_canal,
            'estado' => $this->estado,
            'observaciones' => $this->observaciones,
            'aclaraciones' => $this->aclaraciones,
            'validez_oferta' => $this->validez_oferta,
            'tiempo_entrega' => $this->tiempo_entrega,
            'forma_pago' => $this->forma_pago,
            'garantia' => $this->garantia,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
