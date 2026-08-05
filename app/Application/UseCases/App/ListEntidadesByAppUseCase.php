<?php

namespace App\Application\UseCases\App;

use Illuminate\Support\Facades\DB;

class ListEntidadesByAppUseCase
{
    /**
     * Returns the entities that have a given app, with pivot metadata.
     * Routes to the read replica via `mysql_read` connection (falls back
     * to master when no replica is provisioned).
     *
     * @return array<int, array<string, mixed>>
     */
    public function execute(int $appId): array
    {
        return DB::connection('mysql_read')
            ->table('app_entidad')
            ->join('entidad', 'app_entidad.entidad_id', '=', 'entidad.id')
            ->where('app_entidad.app_id', $appId)
            ->whereNull('entidad.deleted_at')
            ->select(
                'app_entidad.id as pivot_id',
                'entidad.id',
                'entidad.nombre',
                'entidad.identificacion',
                'entidad.estado as entidad_estado',
                'entidad.dominio',
                'app_entidad.fecha_contrato',
                'app_entidad.fecha_vencimiento',
                'app_entidad.estado as assignment_estado',
                'app_entidad.notas'
            )
            ->orderBy('entidad.nombre')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }
}
