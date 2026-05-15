<?php

namespace App\Application\UseCases;

use App\Models\Entidad;

class ValidateApiKeyUseCase
{
    public function execute(string $apiKey): ?array
    {
        $entidad = Entidad::where('dominio', $apiKey)
            ->where('estado', 'Activo')
            ->first();

        if (!$entidad) {
            return null;
        }

        return [
            'valid' => true,
            'bot_id' => "bot_{$entidad->id}",
            'name' => $entidad->nombre,
            'permissions' => [],
        ];
    }
}