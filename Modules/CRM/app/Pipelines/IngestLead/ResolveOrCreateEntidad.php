<?php

namespace Modules\CRM\Pipelines\IngestLead;

use App\Models\Entidad;
use Closure;
use Illuminate\Support\Facades\DB;

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

        // 2. Find by domain. Commit 4 dropped `entidad.dominio` — the
        //    domain lives on `presencia_online` (the shared contact
        //    table that covers both persona-level and entity-level
        //    web presences). We join through it instead.
        if (! $entidad && ! empty($data['email_contacto'])) {
            $emailParts = explode('@', $data['email_contacto']);
            if (count($emailParts) === 2) {
                $domain = mb_strtolower($emailParts[1]);
                $freeEmailProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com'];
                if (! in_array($domain, $freeEmailProviders)) {
                    $entidadId = DB::table('presencia_online')
                        ->where('tipo', 'web')
                        ->where('url', 'like', "%{$domain}%")
                        ->value('entidad_id');
                    if ($entidadId) {
                        $entidad = Entidad::find($entidadId);
                    }
                }
            }
        }

        // 3. Create if not resolved. Commit 4 dropped `entidad.ciudad_cod`
        //    (now lives on `direcciones.ciudad_codigo`); Commit 5.5
        //    dropped `entidad.estado` (now derived from `entidad_relacion`
        //    pivot). Inserting with a `prospecto` pivot row keeps the
        //    derived `getEstadoAttribute()` consistent with the legacy
        //    `'Prospecto'` default.
        if (! $entidad) {
            $entidad = Entidad::create([
                'tipo_persona' => $data['tipo_persona'] ?? 'Juridica',
                'identificacion' => $data['identificacion'] ?? 'TEMP-'.time().'-'.rand(100, 999),
                'nombre' => $data['nombre_empresa'],
                'nombre_comercial' => $data['nombre_empresa'],
            ]);
            DB::table('entidad_relacion')->insert([
                'entidad_id' => $entidad->id,
                'tipo_relacion' => 'prospecto',
                'effective_from' => now(),
                'effective_to' => null,
                'frecuencia' => 'unica',
                'recurrencia_cada_meses' => null,
                'vigencia_meses' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $data['entidad'] = $entidad;
        $data['entidad_id'] = $entidad->id;

        return $next($data);
    }
}
