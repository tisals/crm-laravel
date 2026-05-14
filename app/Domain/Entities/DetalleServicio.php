<?php

namespace App\Domain\Entities;

class DetalleServicio
{
    public function __construct(
        public int $id,
        public int $servicio_id,
        public ?int $producto_id = null,
        public ?string $observacion = null,
        public float $cantidad = 0,
        public float $precio = 0,
        public float $descuento = 0,
        public float $sub_total = 0,
        public float $iva = 0,
        public float $total = 0,
        public ?int $created_by = null,
        public ?int $updated_by = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            servicio_id: $data['servicio_id'],
            producto_id: $data['producto_id'] ?? null,
            observacion: $data['observacion'] ?? null,
            cantidad: $data['cantidad'],
            precio: $data['precio'],
            descuento: $data['descuento'] ?? 0,
            sub_total: $data['sub_total'] ?? 0,
            iva: $data['iva'] ?? 0,
            total: $data['total'] ?? 0,
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
            'servicio_id' => $this->servicio_id,
            'producto_id' => $this->producto_id,
            'observacion' => $this->observacion,
            'cantidad' => $this->cantidad,
            'precio' => $this->precio,
            'descuento' => $this->descuento,
            'sub_total' => $this->sub_total,
            'iva' => $this->iva,
            'total' => $this->total,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
