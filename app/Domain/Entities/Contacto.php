<?php

namespace App\Domain\Entities;

class Contacto
{
    public function __construct(
        public int $id,
        public ?int $entidad_id = null,
        public ?string $entidad_nombre = null,
        public string $nombres,
        public string $apellidos,
        public ?string $area = null,
        public ?string $cargo = null,
        public ?string $tel_contacto = null,
        public ?string $movil = null,
        public ?string $email_contacto = null,
        public ?string $email_secundario = null,
        public ?string $rol = null,
        public ?string $etapa = null,
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
            entidad_id: $data['entidad_id'] ?? null,
            entidad_nombre: $data['entidad_nombre'] ?? null,
            nombres: $data['nombres'],
            apellidos: $data['apellidos'],
            area: $data['area'] ?? null,
            cargo: $data['cargo'] ?? null,
            tel_contacto: $data['tel_contacto'] ?? null,
            movil: $data['movil'] ?? null,
            email_contacto: $data['email_contacto'] ?? null,
            email_secundario: $data['email_secundario'] ?? null,
            rol: $data['rol'] ?? null,
            etapa: $data['etapa'] ?? null,
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
            'entidad_id' => $this->entidad_id,
            'entidad_nombre' => $this->entidad_nombre,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'area' => $this->area,
            'cargo' => $this->cargo,
            'tel_contacto' => $this->tel_contacto,
            'movil' => $this->movil,
            'email_contacto' => $this->email_contacto,
            'email_secundario' => $this->email_secundario,
            'rol' => $this->rol,
            'etapa' => $this->etapa,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
