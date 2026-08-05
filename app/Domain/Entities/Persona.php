<?php

namespace App\Domain\Entities;

class Persona
{
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
            'nombre_completo' => trim("{$this->nombres} {$this->apellidos}"),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
