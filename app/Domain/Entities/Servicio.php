<?php

namespace App\Domain\Entities;

class Servicio
{
    public function __construct(
        public int $id,
        public ?int $oportunidad_id = null,
        public int $entidad_id,
        public string $nombre,
        public float $vr_servicio = 0,
        public ?string $fecha_inicio = null,
        public ?string $fecha_fin = null,
        public ?int $prestador_id = null,
        public string $estado = 'Nuevo',
        public ?int $created_by = null,
        public ?int $updated_by = null,
        public ?string $activation_token = null,
        public ?string $plan_id = null,
        public ?string $tier = null,
        public ?string $subscription_id = null,
        public ?array $metadata = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            oportunidad_id: $data['oportunidad_id'] ?? null,
            entidad_id: $data['entidad_id'],
            nombre: $data['nombre'],
            vr_servicio: $data['vr_servicio'] ?? 0,
            fecha_inicio: $data['fecha_inicio'] ?? null,
            fecha_fin: $data['fecha_fin'] ?? null,
            prestador_id: $data['prestador_id'] ?? null,
            estado: $data['estado'] ?? 'Nuevo',
            created_by: $data['created_by'] ?? null,
            updated_by: $data['updated_by'] ?? null,
            activation_token: $data['activation_token'] ?? null,
            plan_id: $data['plan_id'] ?? null,
            tier: $data['tier'] ?? null,
            subscription_id: $data['subscription_id'] ?? null,
            metadata: $data['metadata'] ?? null,
            created_at: $data['created_at'] ?? null,
            updated_at: $data['updated_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'oportunidad_id' => $this->oportunidad_id,
            'entidad_id' => $this->entidad_id,
            'nombre' => $this->nombre,
            'vr_servicio' => $this->vr_servicio,
            'fecha_inicio' => $this->fecha_inicio,
            'fecha_fin' => $this->fecha_fin,
            'prestador_id' => $this->prestador_id,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'activation_token' => $this->activation_token,
            'plan_id' => $this->plan_id,
            'tier' => $this->tier,
            'subscription_id' => $this->subscription_id,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
