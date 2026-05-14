<?php

namespace App\Application\UseCases;

use App\Models\Entidad;

class ValidateApiKeyUseCase
{
    public function execute(?string $apiKey, ?string $originDomain = null): array
    {
        // 1. Validar que exista la API key
        if (!$apiKey) {
            return [
                'valid' => false,
                'error' => 'API key required. Usa el header X-API-Key.',
            ];
        }

        // 2. Buscar entidad por API key (usando el campo dominio como API key temporal)
        // Nota: En una implementación real, crearías una tabla api_keys dedicada
        // Por ahora usamos el campo 'dominio' como API key para testing
        $entidad = Entidad::where('dominio', $apiKey)->first();

        if (!$entidad) {
            return [
                'valid' => false,
                'error' => 'API key inválida.',
            ];
        }

        // 3. Validar que la entidad esté activa
        if ($entidad->estado !== 'Activo') {
            return [
                'valid' => false,
                'error' => 'Entidad inactiva. Contacta al administrador.',
            ];
        }

        // 4. Validar dominio permitido si se proporciona
        if ($originDomain && !$entidad->isDomainAllowed($originDomain)) {
            return [
                'valid' => false,
                'error' => 'Dominio no permitido. Configura allowed_domains en la entidad.',
            ];
        }

        return [
            'valid' => true,
            'organization_id' => $entidad->id,
            'organization_name' => $entidad->nombre,
            'permissions' => ['read', 'write'],
        ];
    }
}