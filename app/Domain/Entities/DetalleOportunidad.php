<?php

namespace App\Domain\Entities;

class DetalleOportunidad
{
    public function __construct(
        public int $id,
        public int $oportunidad_id,
        public int $producto_id,
        public ?string $concepto = null,
        public ?string $descripcion = null,
        public ?string $medida = 'Und',
        public float $cantidad = 0,
        public ?float $descuento = 0,
        public float $vr_unitario = 0,
        public float $iva = 0,
        public float $vr_total = 0,
        public ?string $notas = null,
        public ?int $created_by = null,
        public ?int $updated_by = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            oportunidad_id: $data['oportunidad_id'],
            producto_id: $data['producto_id'],
            concepto: $data['concepto'] ?? null,
            descripcion: $data['descripcion'] ?? null,
            medida: $data['medida'] ?? 'Und',
            cantidad: $data['cantidad'],
            descuento: isset($data['descuento']) && $data['descuento'] !== null ? (float) $data['descuento'] : 0,
            vr_unitario: $data['vr_unitario'],
            iva: $data['iva'] ?? 0,
            vr_total: $data['vr_total'] ?? 0,
            notas: $data['notas'] ?? null,
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
            'producto_id' => $this->producto_id,
            'concepto' => $this->concepto,
            'descripcion' => $this->descripcion,
            'medida' => $this->medida,
            'cantidad' => $this->cantidad,
            'descuento' => $this->descuento,
            'vr_unitario' => $this->vr_unitario,
            'iva' => $this->iva,
            'vr_total' => $this->vr_total,
            'notas' => $this->notas,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
