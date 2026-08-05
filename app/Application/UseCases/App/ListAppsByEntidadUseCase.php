<?php

namespace App\Application\UseCases\App;

use Illuminate\Support\Facades\DB;

class ListAppsByEntidadUseCase
{
    /**
     * Returns the apps currently assigned to an entity, including the
     * pivot metadata (fecha_contrato, estado, etc).
     *
     * @return array<int, array<string, mixed>>
     */
    public function execute(int $entidadId): array
    {
        return DB::table('app_entidad')
            ->join('apps', 'app_entidad.app_id', '=', 'apps.id')
            ->where('app_entidad.entidad_id', $entidadId)
            ->whereNull('apps.deleted_at')
            ->select(
                'app_entidad.id as pivot_id',
                'apps.id',
                'apps.slug',
                'apps.nombre',
                'apps.tipo',
                'apps.auth_type',
                'apps.activo',
                'app_entidad.fecha_contrato',
                'app_entidad.fecha_vencimiento',
                'app_entidad.estado',
                'app_entidad.notas'
            )
            ->orderBy('apps.nombre')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }
}
