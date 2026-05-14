<?php

namespace App\Application\DTOs;

use App\Models\Usuario;

class LoginResponse
{
    public function __construct(
        public string $token,
        public Usuario $usuario,
    ) {}

    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'usuario' => [
                'id' => $this->usuario->id,
                'nombre' => $this->usuario->nombre,
                'email' => $this->usuario->email,
                'rol_id' => $this->usuario->rol_id,
                'estado' => $this->usuario->estado,
            ],
        ];
    }
}
