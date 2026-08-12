<?php

namespace App\Infrastructure\Persistence;

use App\Models\ServicioApp;

/**
 * ServiciosAppRepository — queries the servicio_app pivot.
 */
class ServiciosAppRepository
{
    /**
     * Returns distinct app slugs of active apps for a given entity.
     *
     * Rules:
     * - estado = 'activo'
     - fecha_vencimiento IS NULL OR fecha_vencimiento >= today
     * - app.activo = true
     */
    public function contratadasActivas(int $entidadId): array
    {
        return ServicioApp::query()
            ->join('servicios as s', 's.id', '=', 'servicio_app.servicio_id')
            ->join('apps as a', 'a.id', '=', 'servicio_app.app_id')
            ->where('s.entidad_id', $entidadId)
            ->whereNull('s.deleted_at')
            ->where('servicio_app.estado', 'activo')
            ->where('a.activo', true)
            ->where(function ($q) {
                $q->whereNull('servicio_app.fecha_vencimiento')
                    ->orWhere('servicio_app.fecha_vencimiento', '>=', now()->toDateString());
            })
            ->distinct()
            ->pluck('a.slug')
            ->toArray();
    }
}
