<?php

namespace App\Application\DTOs;

use App\Models\Usuario;

class LoginResponse
{
    public function __construct(
        public string $token,
        public Usuario $usuario,
        public array $apps = [],
    ) {}

    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'usuario' => [
                'id' => $this->usuario->id,
                'nombres' => $this->usuario->nombre,
                'apellidos' => '',
                'email' => $this->usuario->email,
                'rol_id' => $this->usuario->rol_id,
                'estado' => $this->usuario->estado,
            ],
            'apps' => $this->apps,
        ];
    }
}
