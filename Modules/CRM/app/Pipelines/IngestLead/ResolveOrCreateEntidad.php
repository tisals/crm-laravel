<?php

namespace Modules\CRM\Pipelines\IngestLead;

use App\Models\Entidad;
use Closure;

class ResolveOrCreateEntidad
{
    public function handle(array $passable, Closure $next)
    {
        $data = $passable;
        $entidad = null;

        // 1. Find by identificacion
        if (! empty($data['identificacion'])) {
            $entidad = Entidad::where('identificacion', $data['identificacion'])->first();
        }

        // 2. Find by domain
        if (! $entidad && ! empty($data['email_contacto'])) {
            $emailParts = explode('@', $data['email_contacto']);
            if (count($emailParts) === 2) {
                $domain = mb_strtolower($emailParts[1]);
                $freeEmailProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com'];
                if (! in_array($domain, $freeEmailProviders)) {
                    $entidad = Entidad::where('dominio', 'like', "%{$domain}%")->first();
                }
            }
        }

        // 3. Create if not resolved
        if (! $entidad) {
            $entidad = Entidad::create([
                'tipo_persona' => $data['tipo_persona'] ?? 'Juridica',
                'identificacion' => $data['identificacion'] ?? 'TEMP-'.time().'-'.rand(100, 999),
                'nombre' => $data['nombre_empresa'],
                'nombre_comercial' => $data['nombre_empresa'],
                'estado' => 'Prospecto',
                'ciudad_cod' => $data['ciudad_cod'] ?? '05001', // Default to Medellin
            ]);
        }

        $data['entidad'] = $entidad;
        $data['entidad_id'] = $entidad->id;

        return $next($data);
    }
}
