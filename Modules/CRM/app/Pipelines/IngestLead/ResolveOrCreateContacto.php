<?php

namespace Modules\CRM\Pipelines\IngestLead;

use Modules\CRM\Models\Contacto;
use Closure;

class ResolveOrCreateContacto
{
    public function handle(array $passable, Closure $next)
    {
        $data = $passable;
        $contacto = null;
        $entidadId = $data['entidad_id'];

        // 1. Find by email within organization
        if (!empty($data['email_contacto'])) {
            $contacto = Contacto::where('entidad_id', $entidadId)
                ->where('email_contacto', $data['email_contacto'])
                ->first();
        }

        // 2. Find by names within organization
        if (!$contacto) {
            $contacto = Contacto::where('entidad_id', $entidadId)
                ->where('nombres', $data['nombres'])
                ->where('apellidos', $data['apellidos'])
                ->first();
        }

        // 3. Create if not found
        if (!$contacto) {
            $contacto = Contacto::create([
                'entidad_id' => $entidadId,
                'nombres' => $data['nombres'],
                'apellidos' => $data['apellidos'],
                'area' => $data['area'] ?? null,
                'cargo' => $data['cargo'] ?? null,
                'tel_contacto' => $data['tel_contacto'] ?? null,
                'movil' => $data['movil'] ?? null,
                'email_contacto' => $data['email_contacto'] ?? null,
                'rol' => $data['rol'] ?? null,
                'etapa' => $data['etapa'] ?? 'Lead',
                'estado' => 'Activo',
                'fuente' => $data['fuente'],
            ]);
        } else {
            // Update cargo or mobile if newly provided
            $dirty = false;
            if (!empty($data['cargo']) && empty($contacto->cargo)) {
                $contacto->cargo = $data['cargo'];
                $dirty = true;
            }
            if (!empty($data['movil']) && empty($contacto->movil)) {
                $contacto->movil = $data['movil'];
                $dirty = true;
            }
            if ($dirty) {
                $contacto->save();
            }
        }

        $data['contacto'] = $contacto;
        $data['contacto_id'] = $contacto->id;

        return $next($data);
    }
}
