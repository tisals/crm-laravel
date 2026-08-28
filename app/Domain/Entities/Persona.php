<?php

namespace App\Domain\Entities;

class Persona
{
    /**
     * PR-A: `tipoPersona` and `entidadId` added so the domain entity mirrors
     * the Eloquent model's iter4 fields. Default for tipo_persona is 'Natural'
     * per REQ-PNCE-005 — the application layer is responsible for setting it
     * before persistence, but the entity carries the default for in-memory
     * construction.
     */
    public function __construct(
        public int $id,
        public ?string $identificacion_tipo = null,
        public ?string $identificacion_numero = null,
        public string $nombres = '',
        public ?string $apellidos = null,
        public ?string $email_principal = null,
        public ?string $telefono_principal = null,
        public ?string $direccion = null,
        public ?string $ciudad = null,
        public ?string $pais = null,
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
            email_principal: $data['email_principal'] ?? null,
            telefono_principal: $data['telefono_principal'] ?? null,
            direccion: $data['direccion'] ?? null,
            ciudad: $data['ciudad'] ?? null,
            pais: $data['pais'] ?? null,
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
            'email_principal' => $this->email_principal,
            'telefono_principal' => $this->telefono_principal,
            'direccion' => $this->direccion,
            'ciudad' => $this->ciudad,
            'pais' => $this->pais,
            'tipo_persona' => $this->tipo_persona,
            'entidad_id' => $this->entidad_id,
            'nombre_completo' => trim("{$this->nombres} {$this->apellidos}"),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
