<?php

namespace App\Domain\Entities;

class Entidad
{
    /**
     * Commit 4 dropped the contact-data fields (`email`, `telefono`,
     * `direccion`, `ciudad_cod`, `dominio`, `red_social_url`) that
     * previously lived inline on this entity. They now live in the
     * shared `emails` / `telefonos` / `direcciones` /
     * `presencia_online` tables (Commit 3), reached via the Eloquent
     * `Entidad` model's `emails()` / `telefonos()` / `direcciones()` /
     * `presenciaOnline()` relations.
     *
     * `ciudad_nombre` survives as a derived/denormalized read for
     * callers that want a single string; it now resolves from the
     * primary `direcciones.ciudad_codigo` via the joined
     * `ciudades.cod_municipio` (see `EloquentEntidadRepository`).
     */
    public function __construct(
        public int $id,
        public string $tipo_persona,
        public ?string $tipo_id,
        public ?string $identificacion,
        public string $nombre,
        public ?string $nombre_comercial = null,
        public ?string $rut = null,
        public ?string $logo = null,
        public string $estado = 'Activo',
        public ?int $created_by = null,
        public ?int $updated_by = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
        public ?int $contactos_count = null,
        public ?int $oportunidades_count = null,
        public ?string $comercial_asignado = null,
        public ?string $ciudad_nombre = null,
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
            rut: $data['rut'] ?? null,
            logo: $data['logo'] ?? null,
            estado: $data['estado'] ?? 'Activo',
            created_by: $data['created_by'] ?? null,
            updated_by: $data['updated_by'] ?? null,
            created_at: $data['created_at'] ?? null,
            updated_at: $data['updated_at'] ?? null,
            contactos_count: $data['contactos_count'] ?? null,
            oportunidades_count: $data['oportunidades_count'] ?? null,
            comercial_asignado: $data['comercial_asignado'] ?? null,
            ciudad_nombre: $data['ciudad_nombre'] ?? null,
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
            'rut' => $this->rut,
            'logo' => $this->logo,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'contactos_count' => $this->contactos_count,
            'oportunidades_count' => $this->oportunidades_count,
            'comercial_asignado' => $this->comercial_asignado,
            'ciudad_nombre' => $this->ciudad_nombre,
        ];
    }
}
