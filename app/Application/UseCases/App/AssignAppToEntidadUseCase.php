<?php

namespace App\Application\UseCases\App;

use Illuminate\Support\Facades\DB;

class AssignAppToEntidadUseCase
{
    /**
     * Assigns an app to an entity. Idempotent: if the assignment exists,
     * updates the metadata (estado, fechas, notas) instead of duplicating.
     *
     * Returns the pivot record id.
     */
    public function execute(int $appId, int $entidadId, array $metadata = []): int
    {
        $defaults = [
            'fecha_contrato' => now()->toDateString(),
            'fecha_vencimiento' => null,
            'estado' => 'Activo',
            'notas' => null,
            'created_by' => null,
        ];

        $data = array_merge($defaults, array_intersect_key($metadata, $defaults));

        // Update or insert
        $existing = DB::table('app_entidad')
            ->where('app_id', $appId)
            ->where('entidad_id', $entidadId)
            ->first();

        if ($existing) {
            DB::table('app_entidad')
                ->where('id', $existing->id)
                ->update(array_merge($data, [
                    'updated_at' => now(),
                ]));

            return $existing->id;
        }

        return DB::table('app_entidad')->insertGetId(array_merge($data, [
            'app_id' => $appId,
            'entidad_id' => $entidadId,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
