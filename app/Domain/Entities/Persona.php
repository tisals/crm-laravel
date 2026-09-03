<?php

namespace App\Domain\Entities;

class Persona
{
    /**
     * PR-A added `tipoPersona` + `entidadId` so the domain entity mirrors
     * the Eloquent model's iter4 fields. Default for tipo_persona is 'Natural'
     * per REQ-PNCE-005 — the application layer is responsible for setting it
     * before persistence, but the entity carries the default for in-memory
     * construction.
     *
     * Commit 4 dropped the contact-data fields (`email_principal`,
     * `telefono_principal`, `direccion`, `ciudad`, `pais`) because
     * those values now live in the shared `emails` / `telefonos` /
     * `direcciones` tables (Commit 3). The corresponding getters on
     * the Eloquent model return `Collection`s; the domain entity just
     * carries the persona identity (FK targets) so callers reach for
     * the shared contact tables explicitly.
     *
     * Commit 7a2d33c had already dropped `tipo_persona` from the
     * `personas` table (it lives on `entidad` now), so the entity
     * keeps the field as an optional legacy read for any caller that
     * still passes it through `fromArray`.
     */
    public function __construct(
        public int $id,
        public ?string $identificacion_tipo = null,
        public ?string $identificacion_numero = null,
        public string $nombres = '',
        public ?string $apellidos = null,
        public ?string $tipo_persona = 'Natural',
        public ?int $entidad_id = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
        public ?string $deleted_at = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            identificacion_tipo: $data['identificacion_tipo'] ?? null,
            identificacion_numero: $data['identificacion_numero'] ?? null,
            nombres: $data['nombres'] ?? '',
            apellidos: $data['apellidos'] ?? null,
            tipo_persona: $data['tipo_persona'] ?? 'Natural',
            entidad_id: isset($data['entidad_id']) ? (int) $data['entidad_id'] : null,
            created_at: $data['created_at'] ?? null,
            updated_at: $data['updated_at'] ?? null,
            deleted_at: $data['deleted_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'identificacion_tipo' => $this->identificacion_tipo,
            'identificacion_numero' => $this->identificacion_numero,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'tipo_persona' => $this->tipo_persona,
            'entidad_id' => $this->entidad_id,
            'nombre_completo' => trim("{$this->nombres} {$this->apellidos}"),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
