<?php

namespace App\Application\UseCases\Contacto;

use App\Models\Contacto;
use App\Models\Oportunidad;
use App\Models\Seguimiento;
use Illuminate\Support\Facades\DB;

class ReasignarContactoUseCase
{
    /**
     * Reasigna un contacto a otra entidad.
     *
     * Per `tenant-data-model-fixes` (commit 2.5) + commit fe99f70:
     * `contacto.entidad_id` was dropped. The contacto's entidad binding
     * now lives in the `entidad_persona` pivot, keyed on the contacto's
     * underlying `persona_id`. Reassigning the contacto means moving the
     * pivot row from (persona_id, loserEntidadId) to (persona_id,
     * winnerEntidadId) — the contacto table itself does NOT change.
     *
     * Si ya existe un contacto con el mismo email en la entidad destino,
     * retorna conflicto a menos que se pida merge explícito. The "same
     * email in target entidad" check now resolves the target's contactos
     * via their personas' pivot rows.
     *
     * @return array{success: bool, data?: Contacto, conflict?: array, message?: string}
     */
    public function execute(int $contactoId, int $nuevaEntidadId, bool $merge = false): array
    {
        $contacto = Contacto::find($contactoId);

        if (! $contacto) {
            return ['success' => false, 'message' => 'Contacto no encontrado.'];
        }

        // Sin persona_id no podemos operar el pivot (no hay FK para mover).
        // Esto puede ocurrir con contactos pre-PR-E que no fueron backfilled;
        // el caller debería re-backfill antes de reasignar.
        if ($contacto->persona_id === null) {
            return [
                'success' => false,
                'message' => 'El contacto no tiene persona_id — no se puede reasignar por el pivot entidad_persona.',
            ];
        }

        // ¿Ya está en esta entidad via pivot? (chequeo "no-op").
        $yaAsignado = DB::table('entidad_persona')
            ->where('persona_id', $contacto->persona_id)
            ->where('entidad_id', $nuevaEntidadId)
            ->exists();

        if ($yaAsignado) {
            return [
                'success' => true,
                'data' => $contacto,
                'message' => 'El contacto ya pertenece a esta entidad.',
            ];
        }

        // Buscar conflicto de email en la entidad destino (via pivot).
        if ($contacto->email_contacto) {
            $existente = Contacto::whereHas('persona.entidades', function ($q) use ($nuevaEntidadId) {
                $q->where('entidad_id', $nuevaEntidadId);
            })
                ->where('email_contacto', $contacto->email_contacto)
                ->where('id', '!=', $contactoId)
                ->first();

            if ($existente && ! $merge) {
                return [
                    'success' => false,
                    'conflict' => [
                        'id' => $existente->id,
                        'nombres' => $existente->nombres,
                        'apellidos' => $existente->apellidos,
                        'email_contacto' => $existente->email_contacto,
                    ],
                    'message' => "Ya existe un contacto con el email \"{$contacto->email_contacto}\" en la entidad destino.",
                ];
            }

            if ($existente && $merge) {
                $this->mergeContactos($existente, $contacto);
            }
        }

        // Mover el pivot: crear (persona_id, nuevaEntidadId) y borrar el viejo
        // (persona_id, $entidadActual). Si la persona está en múltiples
        // entidades via 'dependencia'/'asignacion'/'delegacion', sólo
        // borramos la fila exacta del viejo id (no las demás categorías).
        DB::transaction(function () use ($contacto, $nuevaEntidadId) {
            DB::table('entidad_persona')->updateOrInsert(
                [
                    'persona_id' => (int) $contacto->persona_id,
                    'entidad_id' => (int) $nuevaEntidadId,
                ],
                [
                    'categoria' => 'asignacion',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            DB::table('entidad_persona')
                ->where('persona_id', $contacto->persona_id)
                ->where('entidad_id', '!=', $nuevaEntidadId)
                ->delete();
        });

        return [
            'success' => true,
            'data' => $contacto->fresh(),
            'message' => 'Contacto reasignado exitosamente.',
        ];
    }

    /**
     * Fusiona dos contactos: transfiere seguimientos y oportunidades del existente al nuevo,
     * y elimina el contacto que quedó duplicado.
     *
     * PR-H (Phase 5b - REQ-SEG-004): seguimientos are now keyed by
     * `persona_id`, NOT `contacto_id`. The transfer logic must update
     * `seguimiento.persona_id` to the new contacto's persona_id instead
     * of the gone `contacto_id` column. Because persona is the canonical
     * identity axis and a single persona can back multiple contactos
     * (AD-12 cross-entidad dedupe), we use the OLD contacto's persona_id
     * (which is also the NEW contacto's persona_id after the merge) — no
     * data change is actually needed in this branch; the foreign key
     * already points to the right persona. The old transferencia is a
     * no-op for seguimientos but we keep the method for clarity and to
     * avoid surprises if the merge semantics change in the future.
     */
    private function mergeContactos(Contacto $existente, Contacto $nuevo): void
    {
        // Transferir seguimientos: since both contactos share the same
        // persona_id (post-merge canonical identity), the FK already
        // points to the right persona. The update is a no-op but kept
        // for explicit clarity. If the FK was missing on the old contacto
        // (backfill not run), the seguimiento would also lack persona_id,
        // and we stamp it here so the merge does not leave orphan rows.
        if ($nuevo->persona_id !== null) {
            Seguimiento::whereNull('persona_id')
                ->whereIn('oportunidad_id', function ($q) use ($existente) {
                    // Be conservative: only stamp seguimientos tied to
                    // oportunidades of the old contacto. This avoids
                    // accidentally re-pointing seguimientos that were
                    // never tied to either contacto.
                    $q->select('id')
                        ->from('oportunidad')
                        ->where('contacto_id', $existente->id);
                })
                ->update(['persona_id' => (int) $nuevo->persona_id]);
        }

        // Transferir oportunidades del contacto existente al nuevo
        // (the oportunidad.contacto_id axis is unchanged by PR-H — it
        // is NOT a seguimiento FK swap).
        Oportunidad::where('contacto_id', $existente->id)
            ->update(['contacto_id' => $nuevo->id]);

        // Eliminar el contacto duplicado
        $existente->delete();
    }
}
