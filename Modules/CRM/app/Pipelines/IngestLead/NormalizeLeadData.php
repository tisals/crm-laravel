<?php

namespace Modules\CRM\Pipelines\IngestLead;

use Closure;

class NormalizeLeadData
{
    public function handle(array $passable, Closure $next)
    {
        $data = $passable;

        // Trim strings
        array_walk_recursive($data, function (&$val) {
            if (is_string($val)) {
                $val = trim($val);
            }
        });

        // Normalize email
        if (isset($data['email'])) {
            $data['email_contacto'] = mb_strtolower($data['email']);
        } elseif (isset($data['email_contacto'])) {
            $data['email_contacto'] = mb_strtolower($data['email_contacto']);
        }

        // Clean names
        $data['nombres'] = $data['nombres'] ?? $data['nombre_contacto'] ?? 'Lead';
        $data['apellidos'] = $data['apellidos'] ?? $data['apellido_contacto'] ?? 'Temporal';

        // Normalize phone
        $data['movil'] = $data['movil'] ?? $data['telefono_contacto'] ?? $data['celular'] ?? null;

        // Resolve company name
        $data['nombre_empresa'] = $data['nombre_empresa'] ?? $data['empresa'] ?? $data['entidad_nombre'] ?? 'Empresa Temporal';

        // UTM parameters
        $data['fuente'] = $data['fuente'] ?? $data['utm_source'] ?? 'SAIlus Ingestion';
        $data['fuente_canal'] = $data['fuente_canal'] ?? $data['utm_medium'] ?? 'Automated Ingest';

        return $next($data);
    }
}
