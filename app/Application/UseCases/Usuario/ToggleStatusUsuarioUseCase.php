<?php

namespace App\Application\UseCases\Usuario;

use App\Models\Usuario;

class ToggleStatusUsuarioUseCase
{
    public function execute(int $id): mixed
    {
        $usuario = Usuario::findOrFail($id);

        $usuario->estado = $usuario->estado === 'Activo' ? 'Inactivo' : 'Activo';
        $usuario->save();

        return [
            'id' => $usuario->id,
            'estado' => $usuario->estado,
        ];
    }
}
