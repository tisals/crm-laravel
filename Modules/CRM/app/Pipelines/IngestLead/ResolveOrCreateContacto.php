<?php

namespace Modules\CRM\Pipelines\IngestLead;

use App\Models\Contacto;
use Closure;
use Illuminate\Support\Facades\DB;

class ResolveOrCreateContacto
{
    public function handle(array $passable, Closure $next)
    {
        $data = $passable;
        $contacto = null;
        $entidadId = $data['entidad_id'];

        // Commit 2/fe99f70 dropped `contacto.entidad_id`. The contacto →
        // entidad binding now goes through `entidad_persona` keyed on the
        // contacto's persona_id. The lookup pattern is:
        //   1. Resolve a persona_id by email (canonical contact-data
        //      lookup goes through the `emails` table — `contacto.email_contacto`
        //      is a legacy denormalised copy we keep for back-compat).
        //   2. Pick the contacto whose persona_id is bound to the target
        //      entidad via `entidad_persona`.
        //
        // For name lookups (no email in the payload), we fall back to
        // `contacto.nombres` + `contacto.apellidos` filtered by the pivot.
        if (! empty($data['email_contacto'])) {
            $personaId = DB::table('emails')
                ->where('email', $data['email_contacto'])
                ->value('persona_id');
            if ($personaId) {
                $contacto = Contacto::where('persona_id', $personaId)
                    ->whereExists(function ($q) use ($entidadId) {
                        $q->select(DB::raw(1))
                            ->from('entidad_persona')
                            ->whereColumn('entidad_persona.persona_id', 'contacto.persona_id')
                            ->where('entidad_persona.entidad_id', (int) $entidadId);
                    })
                    ->first();
            }
        }

        // 2. Find by names within organization. Same pivot logic.
        if (! $contacto && ! empty($data['nombres'])) {
            $contacto = Contacto::where('nombres', $data['nombres'])
                ->where('apellidos', $data['apellidos'] ?? null)
                ->whereExists(function ($q) use ($entidadId) {
                    $q->select(DB::raw(1))
                        ->from('entidad_persona')
                        ->whereColumn('entidad_persona.persona_id', 'contacto.persona_id')
                        ->where('entidad_persona.entidad_id', (int) $entidadId);
                })
                ->first();
        }

        // 3. Create if not found. Commit 4 dropped `contacto.entidad_id`
        // from `$fillable`; the factory's `afterCreating` hook mirrors
        // the caller's intent into `entidad_persona` instead. We pass
        // `entidad_id` as a non-fillable arg via the factory's
        // `forEntidad()` helper.
        if (! $contacto) {
            $contacto = Contacto::factory()
                ->forEntidad((int) $entidadId)
                ->create([
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
                    'diagnostico_data' => $data['diagnostico_data'] ?? null,
                ]);
        } else {
            // Update cargo, mobile, or diagnostico_data if newly provided
            $dirty = false;
            if (! empty($data['cargo']) && empty($contacto->cargo)) {
                $contacto->cargo = $data['cargo'];
                $dirty = true;
            }
            if (! empty($data['movil']) && empty($contacto->movil)) {
                $contacto->movil = $data['movil'];
                $dirty = true;
            }
            if (! empty($data['diagnostico_data']) && empty($contacto->diagnostico_data)) {
                $contacto->diagnostico_data = $data['diagnostico_data'];
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
